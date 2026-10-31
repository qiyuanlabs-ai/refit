<?php

declare(strict_types=1);

namespace Refit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refit\Project\Project;
use Refit\Project\ProjectInspector;
use Refit\Scanner;

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

    public function testSymlinkedFilesAndDirectoriesAreNotPartOfTheProject(): void
    {
        $root = sys_get_temp_dir() . '/refit-links-' . bin2hex(random_bytes(4));
        $project = $root . '/project';
        $outside = $root . '/outside';
        mkdir($project . '/includes', 0777, true);
        mkdir($outside . '/lib', 0777, true);
        file_put_contents($project . '/local.php', "<?php\n");
        file_put_contents($project . '/real.php', "<?php\n");
        file_put_contents($outside . '/shared.php', "<?php\n");
        file_put_contents($outside . '/lib/old.php', "<?php\n");
        symlink('real.php', $project . '/alias.php');
        symlink('../../outside/shared.php', $project . '/includes/shared.php');
        symlink('../outside/lib', $project . '/linked-lib');

        try {
            $inspected = (new ProjectInspector())->inspect($project);

            self::assertSame(['local.php', 'real.php'], $inspected->phpFiles);
            self::assertNotContains('alias.php', $inspected->phpFiles);
            self::assertNotContains('includes/shared.php', $inspected->phpFiles);
            self::assertNotContains('linked-lib/old.php', $inspected->phpFiles);
        } finally {
            Scanner::removeDirectory($root);
        }
    }

    public function testComposerInstalledJsonIsFoundWithoutGlobBrace(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Project/ProjectInspector.php');
        self::assertDoesNotMatchRegularExpression('/\bglob\s*\([^;]*GLOB_BRACE/', $source);

        $root = sys_get_temp_dir() . '/refit-glob-' . bin2hex(random_bytes(4));
        $installed = [
            'vendor/composer/installed.json' => 'acme/root',
            'plugins/vendor/composer/installed.json' => 'acme/one',
            'plugins/extra/vendor/composer/installed.json' => 'acme/two',
            'plugins/extra/nested/vendor/composer/installed.json' => 'acme/three',
        ];
        foreach ($installed as $relative => $name) {
            $path = $root . '/' . $relative;
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode(['packages' => [['name' => $name, 'version' => '1.2.3']]]));
        }
        file_put_contents($root . '/index.php', "<?php\n");

        try {
            $project = (new ProjectInspector())->inspect($root);
            $names = array_map(static fn ($library): string => $library->name, $project->libraries);
            $vendorPaths = [];
            foreach ($project->libraries as $library) {
                if ($library->name === 'vendor') {
                    $vendorPaths[] = $library->path;
                }
            }
            sort($vendorPaths);

            self::assertContains('acme/root', $names);
            self::assertContains('acme/one', $names);
            self::assertContains('acme/two', $names);
            self::assertNotContains('acme/three', $names);
            self::assertSame(['plugins/extra/vendor', 'plugins/vendor', 'vendor'], $vendorPaths);
        } finally {
            Scanner::removeDirectory($root);
        }
    }
}
