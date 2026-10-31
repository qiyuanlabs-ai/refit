<?php

declare(strict_types=1);

namespace Refit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refit\Project\Project;
use Refit\Project\ProjectInspector;

final class ProjectInspectorTest extends TestCase
{
    public function testReadsWordPressPluginHeadersAndBundledLibraries(): void
    {
        $project = (new ProjectInspector())->inspect(dirname(__DIR__) . '/fixtures/legacy-plugin');

        self::assertSame(Project::WORDPRESS_PLUGIN, $project->type);
        self::assertSame('Legacy Sample Plugin', $project->name);
        self::assertSame('1.2.0', $project->version);
        self::assertSame('7.2', $project->declaredMinPhp);
        self::assertContains('includes/class-exporter.php', $project->phpFiles);

        $library = $project->libraryFor('vendor/acme/lib/src/Old.php');
        self::assertNotNull($library);
        self::assertSame('acme/lib', $library->name);
        self::assertSame('1.0.3', $library->version);
        self::assertNull($project->libraryFor('includes/class-exporter.php'));
        self::assertNotContains('vendor/acme/lib/src/Old.php', $project->ownFiles());
    }

    public function testExcludedDirectoriesAreSkipped(): void
    {
        $project = (new ProjectInspector())->inspect(dirname(__DIR__) . '/fixtures/legacy-plugin', ['includes']);

        self::assertNotContains('includes/class-exporter.php', $project->phpFiles);
    }

    public function testComposerProjectMinimumComesFromRequirePhp(): void
    {
        $dir = sys_get_temp_dir() . '/refit-inspect-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/composer.json', '{"name": "acme/app", "require": {"php": "^7.3 || ^8.0"}}');
        file_put_contents($dir . '/index.php', "<?php\necho 1;\n");

        $project = (new ProjectInspector())->inspect($dir);

        self::assertSame(Project::COMPOSER, $project->type);
        self::assertSame('acme/app', $project->name);
        self::assertSame('7.3', $project->declaredMinPhp);

        unlink($dir . '/composer.json');
        unlink($dir . '/index.php');
        rmdir($dir);
    }
}
