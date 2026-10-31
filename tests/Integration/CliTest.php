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

    /**
     * @param list<string> $arguments
     */
    private function refit(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/refit', ...$arguments, '--no-interaction']);
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
