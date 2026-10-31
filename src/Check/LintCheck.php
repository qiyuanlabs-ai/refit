<?php

declare(strict_types=1);

namespace Refit\Check;

use Refit\Finding;
use Symfony\Component\Process\Process;

/**
 * Compiles every file with `php -l` (syntax check only, nothing is executed).
 * A file that fails here cannot be loaded at all on that PHP version.
 */
final class LintCheck
{
    private const PARALLEL = 8;

    public function __construct(private readonly string $phpBinary = PHP_BINARY)
    {
    }

    public function phpVersion(): string
    {
        $process = new Process([$this->phpBinary, '-r', 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, ".", PHP_RELEASE_VERSION;']);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * @param list<string> $files relative to $root
     * @return list<Finding>
     */
    public function run(string $root, array $files): array
    {
        $findings = [];
        $queue = $files;
        /** @var array<string, Process> $running */
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::PARALLEL) {
                $file = array_shift($queue);
                $process = new Process([$this->phpBinary, '-d', 'display_errors=stdout', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-l', $root . '/' . $file]);
                $process->setTimeout(60);
                $process->start();
                $running[$file] = $process;
            }
            foreach ($running as $file => $process) {
                if ($process->isRunning()) {
                    continue;
                }
                unset($running[$file]);
                if ($process->getExitCode() === 0) {
                    continue;
                }
                $finding = $this->parse($file, $process->getOutput() . $process->getErrorOutput());
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
            if ($running !== []) {
                usleep(5000);
            }
        }

        return $findings;
    }

    private function parse(string $file, string $output): ?Finding
    {
        if (!preg_match('/(?:PHP )?(Parse error|Fatal error):\s*(.+?) in .+? on line (\d+)/', $output, $m)) {
            return null;
        }

        return new Finding(
            $file,
            (int) $m[3],
            Finding::BREAKS,
            'Lint.' . str_replace(' ', '', $m[1]),
            trim($m[2]),
            'php -l',
        );
    }
}
