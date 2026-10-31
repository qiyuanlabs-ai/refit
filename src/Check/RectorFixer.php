<?php

declare(strict_types=1);

namespace Refit\Check;

use Rector\Php74\Rector\ArrayDimFetch\CurlyToSquareBracketArrayStringRector;
use Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector;
use Refit\Project\ProjectInspector;
use Refit\Rules\RuleCatalog;
use Refit\Tools;
use Symfony\Component\Process\Process;

/**
 * Applies Refit's compatibility rules with Rector, in place, to the given directory.
 *
 * Runs in two passes: first the syntax fixes that make a file parseable on PHP 8 (curly brace
 * offsets, unparenthesized nested ternaries), then everything else. Rector needs to understand
 * a whole file before most rules can work on it, so old syntax would otherwise block them.
 */
final class RectorFixer
{
    /**
     * Refit's dynamic property rule writes what it changed to the file named here, for the
     * report. Kept here rather than on the rule so this process never loads Rector's classes,
     * whose bundled Composer autoloader would hide Refit's own installed packages.
     */
    public const DYNAMIC_PROPERTIES_LOG_ENV = 'REFIT_DYNAMIC_PROPERTIES_LOG';

    private const SYNTAX_RULES = [
        CurlyToSquareBracketArrayStringRector::class,
        ParenthesizeNestedTernaryRector::class,
    ];

    /**
     * @param list<string> $skip directories (relative to $root) to leave untouched
     * @param list<string> $excluded directory names to leave untouched wherever they are
     */
    public function fix(string $root, int $targetPhp, int $minPhp, array $skip, array $excluded, bool $includeOptIn, string $workDir): RectorResult
    {
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new \RuntimeException('Cannot read project directory ' . $root);
        }
        $root = rtrim($resolved, '/');
        // Rector follows a symlinked file and writes the target, which may be outside this
        // project. Move those links aside for the run and put them back afterwards.
        $held = $this->holdPhpSymlinks($root);
        try {
            return $this->fixProject($root, $targetPhp, $minPhp, $skip, $excluded, $includeOptIn, $workDir);
        } finally {
            $this->restoreHeld($held);
        }
    }

    /**
     * @param list<string> $skip directories (relative to $root) to leave untouched
     * @param list<string> $excluded directory names to leave untouched wherever they are
     */
    private function fixProject(string $root, int $targetPhp, int $minPhp, array $skip, array $excluded, bool $includeOptIn, string $workDir): RectorResult
    {
        $rules = RuleCatalog::rulesFor($targetPhp, $minPhp, $includeOptIn);
        $syntax = array_values(array_intersect($rules, self::SYNTAX_RULES));
        $rest = array_values(array_diff($rules, self::SYNTAX_RULES));

        foreach ($excluded as $dir) {
            $skip[] = $dir;
            $skip[] = '*/' . $dir;
        }
        $indent = self::detectIndent($root, $skip);
        $result = new RectorResult();
        foreach ([$syntax, $rest] as $number => $pass) {
            if ($pass !== []) {
                $this->runPass($root, $targetPhp, $pass, $skip, $indent, $workDir, $workDir . '/rector-cache-' . $number, $result);
            }
        }

        return $result;
    }

    /**
     * @param list<class-string> $rules
     * @param list<string> $skip
     * @param array{0: string, 1: int} $indent
     */
    private function runPass(string $root, int $targetPhp, array $rules, array $skip, array $indent, string $workDir, string $cacheDir, RectorResult $result): void
    {
        $log = $workDir . '/dynamic-properties.jsonl';
        $config = $workDir . '/rector-' . bin2hex(random_bytes(4)) . '.php';
        $skipPaths = array_map(static fn (string $dir): string => $root . '/' . $dir . (str_contains($dir, '*') ? '/*' : ''), $skip);

        file_put_contents($config, sprintf(
            <<<'PHP'
<?php
require_once %s;

return \Rector\Config\RectorConfig::configure()
    ->withPaths([%s])
    ->withSkip(%s)
    ->withFileExtensions(['php', 'inc', 'phtml'])
    ->withPhpVersion(%d)
    ->withIndent(%s, %d)
    ->withCache(%s)
    ->withRules(%s);
PHP,
            var_export(Tools::composerAutoload(), true),
            var_export($root, true),
            var_export($skipPaths, true),
            $targetPhp,
            var_export($indent[0], true),
            $indent[1],
            var_export($cacheDir, true),
            var_export($rules, true),
        ));

        // The working directory is an empty scratch folder on purpose: Rector loads
        // vendor/autoload.php from it if present, and we never want to run the project's code.
        $process = new Process([
            PHP_BINARY, '-d', 'memory_limit=4G', Tools::rectorBin(), 'process',
            '--config', $config, '--output-format=json', '--no-progress-bar',
        ], $workDir, [self::DYNAMIC_PROPERTIES_LOG_ENV => $log]);
        $process->setTimeout(3600);
        $process->run();
        @unlink($config);

        $output = $process->getOutput();
        $start = strpos($output, '{');
        $data = $start === false ? null : json_decode(substr($output, $start), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Rector did not produce a report:\n" . $output . $process->getErrorOutput());
        }

        foreach ($data['file_diffs'] ?? [] as $diff) {
            $absolute = $this->absolute((string) $diff['file'], $workDir);
            if ($indent[0] === "\t") {
                IndentRepair::repair($absolute, (string) $diff['diff']);
            }
            $file = $this->relativeToRoot($absolute, $root);
            $result->addDiff($file, (string) $diff['diff'], $diff['applied_rectors'] ?? [], $diff['changes'] ?? []);
        }
        if (is_file($log)) {
            foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $entry['file'] = $this->relativeToRoot((string) $entry['file'], $root);
                    $result->dynamicProperties[] = $entry;
                }
            }
            unlink($log);
        }
        foreach ($data['errors'] ?? [] as $error) {
            $file = isset($error['file']) ? $this->relativeToRoot($this->absolute((string) $error['file'], $workDir), $root) : '';
            $result->addError($file, (int) ($error['line'] ?? 0), (string) ($error['message'] ?? 'unknown error'));
        }
    }

    /**
     * Tabs or spaces, so added lines match the code around them. WordPress code mostly uses tabs.
     *
     * @param list<string> $skip
     * @return array{0: string, 1: int}
     */
    public static function detectIndent(string $root, array $skip): array
    {
        $tabs = 0;
        $spaces = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || array_filter($skip, static fn (string $dir): bool => fnmatch($dir . '/*', $relative)) !== []) {
                continue;
            }
            foreach (array_slice(file($file->getPathname()) ?: [], 0, 400) as $line) {
                if (str_starts_with($line, "\t")) {
                    $tabs++;
                } elseif (preg_match('/^( +)\S/', $line, $m) && !str_starts_with(ltrim($line, ' '), '*')) {
                    $spaces[] = strlen($m[1]);
                }
            }
            if (++$seen >= 50) {
                break;
            }
        }
        if ($tabs > count($spaces)) {
            return ["\t", 1];
        }
        $two = count(array_filter($spaces, static fn (int $n): bool => $n % 4 !== 0 && $n % 2 === 0));

        return [' ', $two > count($spaces) / 3 ? 2 : 4];
    }

    private function absolute(string $path, string $workDir): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $real = realpath($workDir . '/' . $path);

        return $real === false ? $path : $real;
    }

    /**
     * Path of $path relative to $root. Rector returns real paths, while $root may still
     * contain a symlink (a TMPDIR link, for example), so the two strings are compared
     * only after both are resolved.
     */
    private function relativeToRoot(string $path, string $root): string
    {
        $roots = array_unique([rtrim($root, '/'), rtrim(realpath($root) ?: $root, '/')]);
        $candidates = [$path];
        $resolved = realpath($path);
        if ($resolved !== false) {
            $candidates[] = $resolved;
        }
        foreach (array_unique($candidates) as $candidate) {
            foreach ($roots as $base) {
                if ($candidate === $base) {
                    return '';
                }
                $prefix = $base . '/';
                if (str_starts_with($candidate, $prefix)) {
                    return substr($candidate, strlen($prefix));
                }
            }
        }

        return ltrim($path, '/');
    }

    /**
     * Rename PHP symlinks out of Rector's way. The link itself is renamed; the target is not.
     *
     * @return list<array{0: string, 1: string}> held path, original path
     */
    private function holdPhpSymlinks(string $root): array
    {
        $held = [];
        try {
            foreach ($this->phpSymlinkFiles($root) as $path) {
                $aside = $path . '.refit-held';
                if (is_link($aside) || file_exists($aside)) {
                    throw new \RuntimeException('Refusing to move symlink aside because ' . $aside . ' already exists.');
                }
                if (!rename($path, $aside)) {
                    throw new \RuntimeException('Cannot move symlink aside: ' . $path);
                }
                $held[] = [$aside, $path];
            }
        } catch (\Throwable $error) {
            $this->restoreHeld($held);

            throw $error;
        }

        return $held;
    }

    /**
     * @param list<array{0: string, 1: string}> $held
     */
    private function restoreHeld(array $held): void
    {
        foreach (array_reverse($held) as [$aside, $path]) {
            if (is_link($aside) || file_exists($aside)) {
                rename($aside, $path);
            }
        }
    }

    /**
     * Symlinks whose name Rector would open (php, inc, phtml). Directories that are
     * links are not descended into, and neither are VCS or dependency folders.
     *
     * @return list<string>
     */
    private function phpSymlinkFiles(string $root): array
    {
        $skip = ProjectInspector::IGNORED_DIRECTORIES;
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $file) use ($skip): bool {
            if ($file->isDir()) {
                return !$file->isLink() && !in_array($file->getFilename(), $skip, true);
            }

            return $file->isLink();
        });

        $paths = [];
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if (in_array(strtolower($file->getExtension()), ['php', 'inc', 'phtml'], true)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }
}
