<?php

declare(strict_types=1);

namespace Refit\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Refit\Scanner;
use Symfony\Component\Process\Process;

/**
 * Runs bin/refit against the legacy-plugin fixture, the way a user would.
 */
#[Group('slow')]
final class CliTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/legacy-plugin';

    private ?string $workDir = null;

    protected function tearDown(): void
    {
        if ($this->workDir !== null) {
            Scanner::removeDirectory($this->workDir);
        }
    }

    public function testScanReportsOwnCodeAndBundledLibrariesSeparately(): void
    {
        $process = $this->refit(['scan', self::FIXTURE, '--json=-']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('WordPress plugin', $report['project']['type']);
        self::assertSame('7.2', $report['min_php']);
        self::assertSame('8.4', $report['target_php']);

        $codes = [];
        foreach ($report['findings'] as $finding) {
            $codes[$finding['file'] . ':' . $finding['code']] = $finding;
        }

        $create = $codes['legacy-plugin.php:PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved'] ?? null;
        self::assertNotNull($create);
        self::assertSame('breaks', $create['severity']);

        self::assertArrayHasKey('legacy-plugin.php:Refit.DynamicProperty', $codes);
        self::assertArrayHasKey('legacy-plugin.php:Refit.DateTime.UnguardedModify.Found', $codes);

        $bundled = $codes['vendor/acme/lib/src/Old.php:PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved'] ?? null;
        self::assertNotNull($bundled);
        self::assertSame('acme/lib', $bundled['library']);
        self::assertFalse($bundled['fixable'], 'Bundled libraries are never auto-fixed');

        // The guarded DateTime::modify() call inside try/catch must not be reported.
        $dateTimeLines = array_column(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['code'] === 'Refit.DateTime.UnguardedModify.Found',
        ), 'line');
        self::assertCount(1, $dateTimeLines);
    }

    public function testFailOnBreaksReturnsNonZero(): void
    {
        $process = $this->refit(['scan', self::FIXTURE, '--json=-', '--fail-on=breaks']);
        $process->run();

        self::assertSame(1, $process->getExitCode());
    }

    public function testFixRefusesOutsideGitWithoutForce(): void
    {
        $copy = $this->copyFixture();

        $process = $this->refit(['fix', $copy]);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('git', $process->getOutput() . $process->getErrorOutput());
    }

    public function testFixRewritesOwnCodeAndLeavesLibrariesAlone(): void
    {
        $copy = $this->copyFixture();
        $libraryBefore = file_get_contents($copy . '/vendor/acme/lib/src/Old.php');

        $process = $this->refit(['fix', $copy, '--force']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $plugin = file_get_contents($copy . '/legacy-plugin.php');
        $exporter = file_get_contents($copy . '/includes/class-exporter.php');

        self::assertStringNotContainsString('create_function', $plugin);
        self::assertStringNotContainsString('$data{0}', $plugin);
        self::assertStringContainsString('$data[0]', $plugin);
        self::assertStringContainsString('?array $data = null', $plugin);
        self::assertStringContainsString('implode(\',\', $data)', $plugin);
        self::assertMatchesRegularExpression('/public \$\w+;/', $plugin, 'Dynamic properties get declared');
        self::assertStringContainsString('#[\AllowDynamicProperties]', $plugin, 'Classes with an unknown parent get the attribute');
        self::assertStringNotContainsString('each(', $exporter);

        self::assertSame($libraryBefore, file_get_contents($copy . '/vendor/acme/lib/src/Old.php'));

        foreach ([$copy . '/legacy-plugin.php', $copy . '/includes/class-exporter.php'] as $file) {
            $lint = new Process([PHP_BINARY, '-l', $file]);
            $lint->run();
            self::assertSame(0, $lint->getExitCode(), $lint->getOutput());
        }

        // A second scan of the fixed copy finds nothing left to auto-fix in own code.
        $rescan = $this->refit(['scan', $copy, '--json=-']);
        $rescan->run();
        $report = json_decode($rescan->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $report['summary']['own_code']['fixable']);
    }

    public function testFixDoesNotRewriteSymlinkedFilesOutsideTheProject(): void
    {
        $this->workDir = Scanner::makeWorkDir();
        $project = $this->workDir . '/project';
        $outside = $this->workDir . '/outside';
        mkdir($project . '/includes', 0777, true);
        mkdir($outside . '/lib', 0777, true);

        $local = "<?php\n\$name = 'World';\necho \"Hello \${name}\";\n";
        $inside = "<?php\n\$name = 'Inside';\necho \"Hello \${name}\";\n";
        $shared = "<?php\n\$name = 'Outside';\necho \"Hello \${name}\";\n";
        $realTxt = "<?php\n\$name = 'Txt';\necho \"Hello \${name}\";\n";
        $library = "<?php\n\$fn = create_function('', 'return 1;');\n";
        file_put_contents($project . '/local.php', $local);
        file_put_contents($project . '/inside.php', $inside);
        file_put_contents($outside . '/shared.php', $shared);
        file_put_contents($outside . '/real.txt', $realTxt);
        file_put_contents($outside . '/lib/old.php', $library);
        symlink('inside.php', $project . '/alias.php');
        symlink('../../outside/shared.php', $project . '/includes/shared.php');
        symlink('../outside/real.txt', $project . '/via-php.php');
        symlink('../outside/lib', $project . '/linked');

        $process = $this->refit(['fix', $project, '--force']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $fixedLocal = (string) file_get_contents($project . '/local.php');
        $fixedInside = (string) file_get_contents($project . '/inside.php');
        self::assertStringNotContainsString('${name}', $fixedLocal);
        self::assertStringContainsString('{$name}', $fixedLocal);
        self::assertStringNotContainsString('${name}', $fixedInside);
        self::assertStringContainsString('{$name}', $fixedInside);

        self::assertSame($shared, file_get_contents($outside . '/shared.php'));
        self::assertSame($realTxt, file_get_contents($outside . '/real.txt'));
        self::assertSame($library, file_get_contents($outside . '/lib/old.php'));

        self::assertTrue(is_link($project . '/alias.php'));
        self::assertSame('inside.php', readlink($project . '/alias.php'));
        self::assertTrue(is_link($project . '/includes/shared.php'));
        self::assertSame('../../outside/shared.php', readlink($project . '/includes/shared.php'));
        self::assertTrue(is_link($project . '/via-php.php'));
        self::assertSame('../outside/real.txt', readlink($project . '/via-php.php'));
        self::assertTrue(is_link($project . '/linked'));
        self::assertSame('../outside/lib', readlink($project . '/linked'));
        self::assertFileDoesNotExist($project . '/includes/shared.php.refit-held');
        self::assertFileDoesNotExist($project . '/via-php.php.refit-held');
    }

    public function testScanReportIgnoresSymlinksInTheTempDirectory(): void
    {
        $normal = $this->refit(['scan', self::FIXTURE, '--json=-']);
        $normal->run();
        self::assertSame(0, $normal->getExitCode(), $normal->getErrorOutput());

        $target = sys_get_temp_dir() . '/refit-real-tmp-' . bin2hex(random_bytes(3));
        $link = sys_get_temp_dir() . '/refit-symlinked-tmpdir-' . bin2hex(random_bytes(6));
        mkdir($target);
        symlink($target, $link);

        try {
            $linked = $this->refit(['scan', self::FIXTURE, '--json=-'], ['TMPDIR' => $link]);
            $linked->run();
            self::assertSame(0, $linked->getExitCode(), $linked->getOutput() . $linked->getErrorOutput());

            $normalReport = json_decode($normal->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $linkedReport = json_decode($linked->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(7, $linkedReport['summary']['own_code']['breaks']);
            self::assertSame(11, $linkedReport['summary']['own_code']['fixable']);
            self::assertSame($normalReport['summary'], $linkedReport['summary']);
            self::assertSame($this->findingKeys($normalReport), $this->findingKeys($linkedReport));
            foreach ($linkedReport['findings'] as $finding) {
                self::assertMatchesRegularExpression(
                    '#^(includes/class-exporter|legacy-plugin|vendor/acme/lib/src/Old)\\.php$#',
                    $finding['file'],
                );
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            Scanner::removeDirectory($target);
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return list<array<string, mixed>>
     */
    private function findingKeys(array $report): array
    {
        $findings = $report['findings'];
        foreach ($findings as &$finding) {
            ksort($finding);
        }
        unset($finding);
        usort($findings, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['code']] <=> [$b['file'], $b['line'], $b['code']]);

        return $findings;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     */
    private function refit(array $arguments, array $env = []): Process
    {
        $inherited = getenv();
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/refit', ...$arguments, '--no-interaction'],
            null,
            $env === [] ? null : array_replace(is_array($inherited) ? $inherited : [], $env),
        );
        $process->setTimeout(300);

        return $process;
    }

    private function copyFixture(): string
    {
        $this->workDir = Scanner::makeWorkDir();
        $copy = $this->workDir . '/plugin';
        Scanner::copyProject(realpath(self::FIXTURE), $copy, []);

        return $copy;
    }
}
