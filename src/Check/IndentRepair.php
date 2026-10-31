<?php

declare(strict_types=1);

namespace Refit\Check;

/**
 * Rector's printer miscounts tabs when it inserts new statements into tab-indented code: each
 * tab is counted as four columns and then written as four tabs. This puts the indentation of
 * lines Rector added back in line with the code around them.
 */
final class IndentRepair
{
    /**
     * @param string $diff unified diff Rector reported for the file
     */
    public static function repair(string $path, string $diff): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        $changed = false;
        foreach (self::hunks($diff) as [$removed, $added]) {
            $unpaired = [];
            foreach ($added as $newLine => $text) {
                $index = $newLine - 1;
                if (!isset($lines[$index]) || $lines[$index] !== $text || !preg_match('/^\t{2,}/', $text)) {
                    continue;
                }
                $pair = self::sameContent($removed, $text);
                if ($pair !== null) {
                    // A line Rector only re-indented: restore the original indentation.
                    $lines[$index] = $pair;
                    $changed = true;
                } else {
                    $unpaired[] = $index;
                }
            }
            foreach ($unpaired as $index) {
                $indent = self::neighbourIndent($lines, $index, $unpaired);
                if ($indent !== null) {
                    $lines[$index] = $indent . ltrim($lines[$index], "\t");
                    $changed = true;
                }
            }
        }

        if ($changed) {
            file_put_contents($path, implode("\n", $lines) . "\n");
        }
    }

    /**
     * @return list<array{0: list<string>, 1: array<int, string>}> removed lines, and added lines by new line number
     */
    private static function hunks(string $diff): array
    {
        $hunks = [];
        $current = null;
        $newLine = 0;
        foreach (explode("\n", $diff) as $row) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $row, $m)) {
                if ($current !== null) {
                    $hunks[] = $current;
                }
                $current = [[], []];
                $newLine = (int) $m[1];
                continue;
            }
            if ($current === null || $row === '' || str_starts_with($row, '+++') || str_starts_with($row, '---')) {
                continue;
            }
            if ($row[0] === '-') {
                $current[0][] = substr($row, 1);
            } elseif ($row[0] === '+') {
                $current[1][$newLine] = substr($row, 1);
                $newLine++;
            } else {
                $newLine++;
            }
        }
        if ($current !== null) {
            $hunks[] = $current;
        }

        return $hunks;
    }

    /**
     * @param list<string> $removed
     */
    private static function sameContent(array $removed, string $added): ?string
    {
        foreach ($removed as $line) {
            if (trim($line) === trim($added) && $line !== $added) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Indentation of the closest member-level line around $index that Rector did not add.
     *
     * @param list<string> $lines
     * @param list<int> $skip
     */
    private static function neighbourIndent(array $lines, int $index, array $skip): ?string
    {
        foreach ([-1, 1] as $direction) {
            for ($i = $index + $direction; isset($lines[$i]); $i += $direction) {
                if (in_array($i, $skip, true) || trim($lines[$i]) === '') {
                    continue;
                }
                if (trim($lines[$i]) === '{' || str_ends_with(rtrim($lines[$i]), '{')) {
                    break; // the class's opening line: look the other way
                }
                preg_match('/^\t*/', $lines[$i], $m);
                if (str_starts_with(ltrim($lines[$i]), '*')) {
                    continue; // inside a docblock, the leading " *" is misleading
                }

                return $m[0];
            }
        }

        return null;
    }
}
