<?php

declare(strict_types=1);

namespace Refit\Check;

use Refit\Finding;
use Refit\Tools;
use Symfony\Component\Process\Process;

/**
 * Runs PHP_CodeSniffer with PHPCompatibility and Refit's own sniffs.
 */
final class PhpcsCheck
{
    /**
     * @param list<string> $excluded directory names to skip
     * @return list<Finding>
     */
    public function run(string $root, string $targetPhp, array $excluded, string $workDir): array
    {
        $ignore = [];
        foreach ($excluded as $dir) {
            $ignore[] = $root . '/' . $dir . '/*';
            $ignore[] = $root . '/*/' . $dir . '/*';
        }
        $process = new Process([
            PHP_BINARY,
            '-d', 'memory_limit=2G',
            Tools::phpcsBin(),
            '--standard=' . Tools::refitStandard(),
            '--runtime-set', 'installed_paths', Tools::phpcsInstalledPaths(),
            '--runtime-set', 'testVersion', $targetPhp,
            '--extensions=php,inc,phtml',
            '--ignore=' . implode(',', $ignore),
            '--report=json',
            '--parallel=4',
            '-q',
            '--no-cache',
            $root,
        ], $workDir);
        $process->setTimeout(3600);
        $process->run();

        $output = $process->getOutput();
        $start = strpos($output, '{');
        $data = $start === false ? null : json_decode(substr($output, $start), true);
        if (!is_array($data) || !isset($data['files'])) {
            throw new \RuntimeException("PHP_CodeSniffer did not produce a report:\n" . $output . $process->getErrorOutput());
        }

        $findings = [];
        foreach ($data['files'] as $path => $file) {
            $relative = ltrim(substr((string) $path, strlen($root)), '/');
            foreach ($file['messages'] as $message) {
                $source = (string) $message['source'];
                if (str_starts_with($source, 'Internal.')) {
                    continue; // PHPCS's own notices (e.g. mixed line endings), not compatibility issues
                }
                $findings[] = new Finding(
                    $relative,
                    (int) $message['line'],
                    $this->severity($source, (string) $message['type']),
                    $source,
                    (string) $message['message'],
                    'PHPCompatibility',
                );
            }
        }

        return $findings;
    }

    private function severity(string $source, string $type): string
    {
        if (str_starts_with($source, 'Refit.')) {
            return Finding::REVIEW;
        }

        return $type === 'ERROR' ? Finding::BREAKS : Finding::DEPRECATED;
    }
}
