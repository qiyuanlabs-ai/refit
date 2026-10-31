<?php

declare(strict_types=1);

namespace Refit;

use Composer\InstalledVersions;

/**
 * Finds the bundled third-party tools, whether Refit runs from a clone, as a Composer
 * dependency, or as a global install.
 */
final class Tools
{
    public static function rectorBin(): string
    {
        return self::installPath('rector/rector') . '/bin/rector';
    }

    public static function phpcsBin(): string
    {
        return self::installPath('squizlabs/php_codesniffer') . '/bin/phpcs';
    }

    public static function refitStandard(): string
    {
        return dirname(__DIR__) . '/standards/Refit/ruleset.xml';
    }

    /**
     * Directories PHPCS needs to find the PHPCompatibility and PHPCSUtils standards.
     */
    public static function phpcsInstalledPaths(): string
    {
        return implode(',', [
            self::installPath('phpcompatibility/php-compatibility'),
            self::installPath('phpcsstandards/phpcsutils'),
        ]);
    }

    public static function composerAutoload(): string
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        return dirname((string) $loader, 2) . '/autoload.php';
    }

    public static function version(string $package): string
    {
        if ($package === 'qiyuanlabs/refit') {
            return Application::VERSION;
        }

        return InstalledVersions::isInstalled($package) ? (string) InstalledVersions::getPrettyVersion($package) : 'unknown';
    }

    private static function installPath(string $package): string
    {
        $path = InstalledVersions::getInstallPath($package);
        if ($path === null) {
            throw new \RuntimeException(sprintf('Required package %s is not installed. Run composer install.', $package));
        }

        return rtrim((string) realpath($path), '/');
    }
}
