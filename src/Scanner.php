<?php

declare(strict_types=1);

namespace Refit;

use Refit\Check\LintCheck;
use Refit\Check\PhpcsCheck;
use Refit\Check\RectorFixer;
use Refit\Check\RectorResult;
use Refit\Project\Project;
use Refit\Project\ProjectInspector;
use Refit\Rules\PhpVersion;
use Refit\Rules\RuleCatalog;

/**
 * Runs every check on a project without touching it. The automatic fixes are tried on a
 * scratch copy so the report can say which problems `refit fix` would take care of.
 */
final class Scanner
{
    public const DEFAULT_MIN_PHP = '7.2';

    /** @var callable(string): void */
    private $progress;

    /**
     * @param callable(string): void|null $progress
     */
    public function __construct(?callable $progress = null)
    {
        $this->progress = $progress ?? static function (string $step): void {
        };
    }

    /**
     * @param list<string> $exclude directory names to skip, such as "tests"
     */
    public function scan(string $path, string $targetPhp, ?string $minPhpOption, string $phpBinary, bool $allRules, array $exclude = []): Report
    {
        $workDir = self::makeWorkDir();
        try {
            ($this->progress)('Reading the project');
            $project = (new ProjectInspector())->inspect($path, $exclude);
            [$minPhp, $minPhpSource] = self::resolveMinPhp($project, $minPhpOption);

            $lint = new LintCheck($phpBinary);
            $lintPhp = $lint->phpVersion();
            ($this->progress)(sprintf('Compiling %d files with PHP %s', count($project->phpFiles), $lintPhp));
            $findings = $lint->run($project->root, $project->phpFiles);

            ($this->progress)('Checking compatibility with PHPCompatibility');
            $findings = array_merge($findings, (new PhpcsCheck())->run($project->root, $targetPhp, $project->excluded, $workDir));

            ($this->progress)('Trying the automatic fixes on a copy');
            $copy = $workDir . '/copy';
            self::copyProject($project->root, $copy, $project->excluded);
            $rector = (new RectorFixer())->fix(
                $copy,
                (int) PhpVersion::toId($targetPhp),
                (int) PhpVersion::toId($minPhp),
                self::libraryPaths($project),
                $project->excluded,
                $allRules,
                $workDir,
            );
            $findings = $this->merge($project, $findings, $rector, $minPhp);

            return new Report(
                $project,
                $targetPhp,
                $minPhp,
                $minPhpSource,
                $lintPhp,
                $findings,
                $rector->changedFileCount(),
                [
                    'Refit' => Tools::version('qiyuanlabs/refit'),
                    'Rector' => Tools::version('rector/rector'),
                    'PHP_CodeSniffer' => Tools::version('squizlabs/php_codesniffer'),
                    'PHPCompatibility' => Tools::version('phpcompatibility/php-compatibility'),
                ],
                new \DateTimeImmutable(),
            );
        } finally {
            self::removeDirectory($workDir);
        }
    }

    /**
     * @return array{0: string, 1: string} version and where it came from
     */
    public static function resolveMinPhp(Project $project, ?string $option): array
    {
        if ($option !== null) {
            return [$option, 'set with --min-php'];
        }
        if ($project->declaredMinPhp !== null && PhpVersion::toId($project->declaredMinPhp) !== null) {
            return [PhpVersion::toString((int) PhpVersion::toId($project->declaredMinPhp)), 'declared by the project'];
        }

        return [self::DEFAULT_MIN_PHP, 'default, the project does not declare one'];
    }

    /**
     * @return list<string>
     */
    public static function libraryPaths(Project $project): array
    {
        return array_values(array_unique(array_map(static fn ($library): string => $library->path, $project->libraries)));
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function merge(Project $project, array $findings, RectorResult $rector, string $minPhp): array
    {
        foreach ($findings as $finding) {
            $finding->library = $project->libraryFor($finding->file)?->name;
            if ($rector->touches($finding->file, $finding->line)) {
                $finding->fixable = true;
            }
        }

        $flagged = [];
        foreach ($findings as $finding) {
            $flagged[$finding->file][$finding->line] = true;
        }

        foreach ($rector->dynamicProperties as $entry) {
            $properties = (count($entry['properties']) === 1 ? 'property ' : 'properties ')
                . implode(', ', array_map(static fn (string $p): string => '$' . $p, $entry['properties']));
            $findings[] = new Finding(
                $entry['file'],
                $entry['line'],
                Finding::DEPRECATED,
                'Refit.DynamicProperty',
                $entry['attribute']
                    ? sprintf('%s creates %s without declaring it first. #[\\AllowDynamicProperties] will be added so the properties stay dynamic.', $entry['class'], $properties)
                    : sprintf('%s creates %s without declaring it first.', $entry['class'], $properties),
                'Refit',
                true,
                $project->libraryFor($entry['file'])?->name,
                '8.2',
            );
        }

        // Rector changes that no other check reported get a finding of their own.
        foreach ($rector->changes as $file => $changes) {
            foreach ($changes as $change) {
                if ($change['rule'] === 'DeclareDynamicPropertiesRector' || isset($flagged[$file][$change['line']])) {
                    continue;
                }
                $flagged[$file][$change['line']] = true;
                $findings[] = new Finding(
                    $file,
                    $change['line'],
                    $this->severityForRule($change['rule']),
                    'Refit.Autofix',
                    sprintf('Can be fixed automatically (%s).', $change['rule']),
                    'Rector',
                    true,
                    $project->libraryFor($file)?->name,
                    $this->sinceForRule($change['rule']),
                );
            }
        }

        foreach ($rector->errors as $error) {
            $findings[] = new Finding(
                $error['file'],
                $error['line'],
                Finding::REVIEW,
                'Refit.RectorError',
                $error['message'],
                'Rector',
                false,
                $project->libraryFor($error['file'])?->name,
            );
        }

        foreach ($findings as $finding) {
            $finding->since ??= match (true) {
                str_starts_with($finding->code, 'Refit.DateTime.') => '8.3',
                str_starts_with($finding->code, 'Lint.') => null,
                default => Finding::versionInMessage($finding->message),
            };
            // Already a problem on the oldest PHP the project supports today, so not caused by
            // this upgrade. Often unreachable code, sometimes a long-standing bug.
            $finding->preexisting = $finding->since !== null && version_compare($finding->since, $minPhp, '<=');
        }

        usort($findings, static fn (Finding $a, Finding $b): int => [Finding::SEVERITY_ORDER[$a->severity], $a->file, $a->line]
            <=> [Finding::SEVERITY_ORDER[$b->severity], $b->file, $b->line]);

        return $findings;
    }

    private function severityForRule(string $rule): string
    {
        $since = $this->sinceForRule($rule);

        return $since !== null && version_compare($since, '8.0', '<=') ? Finding::BREAKS : Finding::DEPRECATED;
    }

    private function sinceForRule(string $rule): ?string
    {
        foreach (RuleCatalog::rulesFor(99999, 99999, true) as $class) {
            if (RectorResult::shortName($class) === $rule) {
                return RuleCatalog::since($class);
            }
        }

        return null;
    }

    public static function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/refit-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create a temporary directory in ' . sys_get_temp_dir());
        }
        // Rector reports real paths. A TMPDIR that is itself a symlink (macOS /var, or a
        // link set up on Linux) would make the copy's path a different string of a different
        // length, and stripping that prefix truncates file names in the report.
        $real = realpath($dir);
        if ($real === false) {
            throw new \RuntimeException('Cannot resolve temporary directory ' . $dir);
        }

        return $real;
    }

    /**
     * Copies the PHP and JSON files Rector needs. Excluded directories are left out.
     *
     * @param list<string> $excluded
     */
    public static function copyProject(string $from, string $to, array $excluded): void
    {
        $directory = new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static fn (\SplFileInfo $file): bool => !$file->isLink()
            && !($file->isDir() && in_array($file->getFilename(), $excluded, true)));
        @mkdir($to, 0700, true);
        foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . substr($item->getPathname(), strlen($from));
            if ($item->isDir()) {
                @mkdir($target, 0700, true);
            } elseif (in_array(strtolower($item->getExtension()), ['php', 'inc', 'phtml', 'json'], true)) {
                copy($item->getPathname(), $target);
            }
        }
    }

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
