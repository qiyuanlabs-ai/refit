<?php

declare(strict_types=1);

namespace Refit\Command;

use Refit\Check\LintCheck;
use Refit\Check\RectorFixer;
use Refit\Project\ProjectInspector;
use Refit\Rules\PhpVersion;
use Refit\Scanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'fix', description: 'Apply the safe automatic fixes in place. Bundled libraries are left alone.')]
final class FixCommand extends Command
{
    protected function configure(): void
    {
        Options::configure($this);
        $this
            ->addOption('include-libraries', null, InputOption::VALUE_NONE, 'Also change bundled third-party libraries (usually better to update them)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even if the project is not a git repository or has uncommitted changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = Options::path($input);
        $target = Options::target($input);

        if (!$input->getOption('force') && ($problem = $this->gitProblem($path)) !== null) {
            $io->error($problem . ' Refit changes files in place, so commit first to be able to review and undo the changes. Use --force to skip this check.');

            return Command::FAILURE;
        }

        $project = (new ProjectInspector())->inspect($path, Options::excluded($input));
        [$minPhp, $minPhpSource] = Scanner::resolveMinPhp($project, Options::minPhp($input, $target));
        $skip = $input->getOption('include-libraries') ? [] : Scanner::libraryPaths($project);
        $files = $input->getOption('include-libraries') ? $project->phpFiles : $project->ownFiles();

        $io->writeln(sprintf('Fixing <options=bold>%s</> for PHP %s, keeping it compatible with PHP %s (%s).', $project->name, $target, $minPhp, $minPhpSource));
        if ($skip !== []) {
            $io->writeln(sprintf('Leaving %d bundled library folder(s) alone.', count($skip)));
        }

        // Remember the original content so a fix that breaks compilation can be undone.
        $lint = new LintCheck();
        $originals = [];
        foreach ($files as $file) {
            $originals[$file] = (string) file_get_contents($project->root . '/' . $file);
        }
        $alreadyBroken = [];
        foreach ($lint->run($project->root, $files) as $finding) {
            $alreadyBroken[$finding->file] = true;
        }

        $workDir = Scanner::makeWorkDir();
        try {
            $result = (new RectorFixer())->fix($project->root, (int) PhpVersion::toId($target), (int) PhpVersion::toId($minPhp), $skip, $project->excluded, (bool) $input->getOption('all-rules'), $workDir);
        } finally {
            Scanner::removeDirectory($workDir);
        }

        $changed = array_keys($result->rules);
        $reverted = [];
        foreach ($lint->run($project->root, $changed) as $finding) {
            if (!isset($alreadyBroken[$finding->file]) && isset($originals[$finding->file])) {
                file_put_contents($project->root . '/' . $finding->file, $originals[$finding->file]);
                $reverted[] = sprintf('%s (%s on line %d)', $finding->file, $finding->message, $finding->line);
            }
        }

        $ruleCounts = [];
        foreach ($result->rules as $rules) {
            foreach ($rules as $rule) {
                $ruleCounts[$rule] = ($ruleCounts[$rule] ?? 0) + 1;
            }
        }
        arsort($ruleCounts);

        $io->newLine();
        if ($changed === []) {
            $io->success('Nothing to fix automatically.');
        } else {
            $io->success(sprintf('Changed %d file(s).', count($changed) - count($reverted)));
            $io->listing(array_map(static fn (string $rule, int $count): string => sprintf('%s: %d file(s)', $rule, $count), array_keys($ruleCounts), $ruleCounts));
        }
        if ($reverted !== []) {
            $io->warning("These files no longer compiled after fixing, so they were put back as they were:\n" . implode("\n", $reverted));
        }
        foreach ($result->errors as $error) {
            $io->writeln(sprintf('<comment>Could not process %s: %s</comment>', $error['file'], $error['message']));
        }
        $io->writeln('Review the changes with <info>git diff</info>, then run <info>refit scan</info> again to see what is left for a person to fix.');

        return Command::SUCCESS;
    }

    private function gitProblem(string $path): ?string
    {
        $process = new Process(['git', 'status', '--porcelain', '--', '.'], $path);
        $process->run();
        if (!$process->isSuccessful()) {
            return 'This folder is not in a git repository.';
        }
        if (trim($process->getOutput()) !== '') {
            return 'The git working tree has uncommitted changes.';
        }

        return null;
    }
}
