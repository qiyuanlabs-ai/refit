<?php

declare(strict_types=1);

namespace Refit\Check;

/**
 * What Rector changed, per file, with the original line numbers each change touches.
 */
final class RectorResult
{
    /** @var array<string, list<array{start: int, lines: list<int>, added: list<string>, removed: list<string>, rules: list<string>}>> */
    public array $hunks = [];

    /** @var array<string, list<string>> */
    public array $rules = [];

    /** @var array<string, list<array{rule: string, line: int}>> */
    public array $changes = [];

    /** @var list<array{file: string, line: int, message: string}> */
    public array $errors = [];

    /** @var list<array{file: string, line: int, class: string, properties: list<string>, attribute: bool}> */
    public array $dynamicProperties = [];

    /**
     * @param list<string> $appliedRules
     * @param list<array{rector: string, line: int}> $changes
     */
    public function addDiff(string $file, string $diff, array $appliedRules, array $changes = []): void
    {
        $short = array_map(self::shortName(...), $appliedRules);
        $this->rules[$file] = array_values(array_unique(array_merge($this->rules[$file] ?? [], $short)));
        foreach ($changes as $change) {
            $this->changes[$file][] = ['rule' => self::shortName((string) $change['rector']), 'line' => (int) $change['line']];
        }

        $hunk = null;
        $line = 0;
        foreach (explode("\n", $diff) as $row) {
            if (preg_match('/^@@ Line (\d+) @@/', $row, $m) || preg_match('/^@@ -(\d+)/', $row, $m)) {
                if ($hunk !== null) {
                    $this->hunks[$file][] = $hunk;
                }
                $line = (int) $m[1];
                $hunk = ['start' => $line, 'lines' => [], 'added' => [], 'removed' => [], 'rules' => $short];
                continue;
            }
            if ($hunk === null || $row === '' || str_starts_with($row, '---') || str_starts_with($row, '+++')) {
                continue;
            }
            $mark = $row[0];
            if ($mark === ' ') {
                $line++;
            } elseif ($mark === '-') {
                $hunk['lines'][] = $line;
                $hunk['removed'][] = substr($row, 1);
                $line++;
            } elseif ($mark === '+') {
                // An insertion touches the line it is inserted before.
                $hunk['lines'][] = $line;
                $hunk['added'][] = substr($row, 1);
            }
        }
        if ($hunk !== null) {
            $this->hunks[$file][] = $hunk;
        }
    }

    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    public function addError(string $file, int $line, string $message): void
    {
        $this->errors[] = ['file' => $file, 'line' => $line, 'message' => $message];
    }

    public function changedFileCount(): int
    {
        return count($this->rules);
    }

    public function touches(string $file, int $line): bool
    {
        foreach ($this->hunks[$file] ?? [] as $hunk) {
            if (in_array($line, $hunk['lines'], true)) {
                return true;
            }
        }

        return false;
    }
}
