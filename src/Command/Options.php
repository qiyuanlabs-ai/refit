<?php

declare(strict_types=1);

namespace Refit\Command;

use Refit\Application;
use Refit\Rules\PhpVersion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Options shared by scan and fix.
 */
final class Options
{
    public static function configure(\Symfony\Component\Console\Command\Command $command): void
    {
        $command
            ->addArgument('path', InputArgument::OPTIONAL, 'Project directory', '.')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP version to upgrade to (' . implode(', ', PhpVersion::SUPPORTED_TARGETS) . ')', Application::DEFAULT_TARGET)
            ->addOption('min-php', null, InputOption::VALUE_REQUIRED, 'Oldest PHP version the code must still run on (default: what the project declares, else 7.2)')
            ->addOption('all-rules', null, InputOption::VALUE_NONE, 'Also use fixes that are correct but noisy, like casting possibly-null arguments for PHP 8.1')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directory name to skip wherever it appears (repeatable)')
            ->addOption('include-tests', null, InputOption::VALUE_NONE, 'Also check directories named tests or test, which are skipped by default');
    }

    public static function path(InputInterface $input): string
    {
        $path = (string) $input->getArgument('path');
        if (!is_dir($path)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a directory.', $path));
        }

        return (string) realpath($path);
    }

    public static function target(InputInterface $input): string
    {
        $target = (string) $input->getOption('php');
        if (!in_array($target, PhpVersion::SUPPORTED_TARGETS, true)) {
            throw new \InvalidArgumentException(sprintf('--php must be one of %s.', implode(', ', PhpVersion::SUPPORTED_TARGETS)));
        }

        return $target;
    }

    /**
     * @return list<string>
     */
    public static function excluded(InputInterface $input): array
    {
        $exclude = array_map('strval', (array) $input->getOption('exclude'));
        if (!$input->getOption('include-tests')) {
            array_push($exclude, 'tests', 'test');
        }

        return array_values(array_unique(array_map(static fn (string $dir): string => trim($dir, '/'), $exclude)));
    }

    public static function minPhp(InputInterface $input, string $target): ?string
    {
        $min = $input->getOption('min-php');
        if ($min === null) {
            return null;
        }
        $id = PhpVersion::toId((string) $min);
        if ($id === null || $id < 50300 || $id > PhpVersion::toId($target)) {
            throw new \InvalidArgumentException('--min-php must be a PHP version between 5.3 and the target, like 7.4.');
        }

        return PhpVersion::toString($id);
    }
}
