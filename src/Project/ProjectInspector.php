<?php

declare(strict_types=1);

namespace Refit\Project;

use Refit\Rules\PhpVersion;

/**
 * Works out what kind of project a directory holds, which PHP versions it says it supports,
 * and which third-party libraries are bundled inside it. Reads files only; never runs them.
 */
final class ProjectInspector
{
    public const IGNORED_DIRECTORIES = ['.git', '.svn', '.hg', 'node_modules', '.idea', '.vscode'];

    private const EXTENSIONS = ['php', 'inc', 'phtml'];

    /** Files that identify a bundled library that was copied in without Composer. */
    private const LIBRARY_MARKERS = [
        'freemius/start.php' => 'Freemius SDK',
        'tcpdf.php' => 'TCPDF',
        'class.phpmailer.php' => 'PHPMailer',
        'simple_html_dom.php' => 'simple_html_dom',
        'Mobile_Detect.php' => 'Mobile_Detect',
        'action-scheduler/action-scheduler.php' => 'Action Scheduler',
        'cmb2/init.php' => 'CMB2',
        'plugin-update-checker/plugin-update-checker.php' => 'Plugin Update Checker',
    ];

    /**
     * @param list<string> $exclude extra directory names to skip, such as "tests"
     */
    public function inspect(string $root, array $exclude = []): Project
    {
        $root = rtrim((string) realpath($root), '/');
        $excluded = array_values(array_unique(array_merge(self::IGNORED_DIRECTORIES, $exclude)));
        $files = $this->phpFiles($root, $excluded);

        [$type, $name, $version, $minPhp] = $this->identify($root, $files);
        // Source checkouts often hold a build placeholder such as {{version}} instead of a number.
        if ($version !== null && preg_match('/\d/', (string) $version) !== 1) {
            $version = null;
        }
        $libraries = $this->libraries($root, $files);

        return new Project($root, $type, $name, $version, $minPhp, $files, $libraries, $excluded);
    }

    /**
     * @param list<string> $excluded
     * @return list<string>
     */
    private function phpFiles(string $root, array $excluded): array
    {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $file) use ($excluded): bool {
            // Symlinks are not project files. A link to a directory would pull in another tree,
            // and a link to a file resolves to a target that may live outside this project.
            if ($file->isLink()) {
                return false;
            }
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $excluded, true);
            }

            return in_array(strtolower($file->getExtension()), self::EXTENSIONS, true);
        });

        $files = [];
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    private function identify(string $root, array $files): array
    {
        foreach ($files as $file) {
            if (str_contains($file, '/')) {
                continue;
            }
            $headers = $this->fileHeaders($root . '/' . $file);
            if (isset($headers['plugin name'])) {
                $minPhp = $headers['requires php'] ?? $this->readmeHeader($root, 'requires php');

                return [Project::WORDPRESS_PLUGIN, $headers['plugin name'], $headers['version'] ?? null, $minPhp];
            }
        }

        if (is_file($root . '/style.css')) {
            $headers = $this->fileHeaders($root . '/style.css');
            if (isset($headers['theme name'])) {
                $minPhp = $headers['requires php'] ?? $this->readmeHeader($root, 'requires php');

                return [Project::WORDPRESS_THEME, $headers['theme name'], $headers['version'] ?? null, $minPhp];
            }
        }

        $composer = $this->readJson($root . '/composer.json');
        if ($composer !== null) {
            $minPhp = isset($composer['require']['php']) ? $this->lowestVersion((string) $composer['require']['php']) : null;

            return [Project::COMPOSER, (string) ($composer['name'] ?? basename($root)), $composer['version'] ?? null, $minPhp];
        }

        return [Project::PLAIN, basename($root), null, null];
    }

    /**
     * WordPress-style "Key: value" headers from the first 8 KB of a file, keys lower-cased.
     *
     * @return array<string, string>
     */
    private function fileHeaders(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $headers = [];
        $keys = ['Plugin Name', 'Theme Name', 'Version', 'Requires PHP', 'Requires at least', 'Tested up to'];
        foreach ($keys as $key) {
            if (preg_match('/^[\s\*#@\/]*' . preg_quote($key, '/') . ':(.*)$/mi', $head, $m) && trim($m[1]) !== '') {
                $headers[strtolower($key)] = trim(preg_replace('/\s*(?:\*\/|\?>).*$/', '', $m[1]) ?? '');
            }
        }

        return $headers;
    }

    private function readmeHeader(string $root, string $key): ?string
    {
        foreach (['readme.txt', 'README.txt'] as $readme) {
            if (is_file($root . '/' . $readme)) {
                $headers = $this->fileHeaders($root . '/' . $readme);

                return $headers[$key] ?? null;
            }
        }

        return null;
    }

    private function lowestVersion(string $constraint): ?string
    {
        if (!preg_match_all('/(\d+)\.(\d+)/', $constraint, $m, PREG_SET_ORDER)) {
            return null;
        }
        $ids = array_map(static fn (array $v): int => (int) $v[1] * 10000 + (int) $v[2] * 100, $m);

        return PhpVersion::toString(min($ids));
    }

    /**
     * @param list<string> $files
     * @return list<Library>
     */
    private function libraries(string $root, array $files): array
    {
        $libraries = [];

        // Composer-installed packages, wherever a vendor directory sits.
        foreach ($this->installedComposerFiles($root) as $installed) {
            $vendorDir = dirname($installed, 2);
            $data = $this->readJson($installed);
            $packages = $data['packages'] ?? $data ?? [];
            foreach (is_array($packages) ? $packages : [] as $package) {
                if (!is_array($package) || !isset($package['name'])) {
                    continue;
                }
                $path = isset($package['install-path'])
                    ? $this->normalize($vendorDir . '/composer/' . $package['install-path'])
                    : $vendorDir . '/' . $package['name'];
                $libraries[] = new Library((string) $package['name'], $this->relative($root, $path), isset($package['version']) ? (string) $package['version'] : null);
            }
            // Anything else in vendor/ still counts as third-party code.
            $libraries[] = new Library('vendor', $this->relative($root, $vendorDir));
        }

        // Vendor directories without Composer metadata.
        foreach ($files as $file) {
            if (preg_match('#^(.*?(?:^|/)vendor)/#', $file, $m) && !$this->hasLibraryAt($libraries, $m[1])) {
                $libraries[] = new Library('vendor', $m[1]);
            }
        }

        // Nested packages with their own composer.json, outside vendor/.
        foreach ($files as $file) {
            $dir = dirname($file);
            while ($dir !== '.' && $dir !== '') {
                if (is_file($root . '/' . $dir . '/composer.json') && !$this->hasLibraryAt($libraries, $dir) && !$this->insideLibrary($libraries, $dir)) {
                    $json = $this->readJson($root . '/' . $dir . '/composer.json') ?? [];
                    $libraries[] = new Library((string) ($json['name'] ?? basename($dir)), $dir, isset($json['version']) ? (string) $json['version'] : null);
                }
                $dir = dirname($dir);
            }
        }

        // Well-known libraries copied in by hand.
        foreach ($files as $file) {
            foreach (self::LIBRARY_MARKERS as $marker => $name) {
                if ($file !== $marker && !str_ends_with($file, '/' . $marker)) {
                    continue;
                }
                $dir = str_contains($marker, '/')
                    ? substr($file, 0, -strlen($marker)) . explode('/', $marker)[0]
                    : dirname($file);
                if ($dir !== '.' && !$this->insideLibrary($libraries, $dir)) {
                    $libraries[] = new Library($name, $dir, $this->markerVersion($root . '/' . $file));
                }
            }
        }

        return $libraries;
    }

    /**
     * installed.json at the project root and one or two directories down.
     * Separate globs, because GLOB_BRACE is not defined on musl (Alpine).
     *
     * @return list<string>
     */
    private function installedComposerFiles(string $root): array
    {
        $files = [];
        foreach ([
            '/vendor/composer/installed.json',
            '/*/vendor/composer/installed.json',
            '/*/*/vendor/composer/installed.json',
        ] as $suffix) {
            foreach (glob($root . $suffix) ?: [] as $installed) {
                $files[] = $installed;
            }
        }

        return $files;
    }

    private function markerVersion(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16384);
        if (preg_match('/(?:@version|Version:|\$this_sdk_version\s*=|VERSION\'?\s*,)\s*[\'"]?v?(\d+\.\d+(?:\.\d+)*)/i', $head, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param list<Library> $libraries
     */
    private function hasLibraryAt(array $libraries, string $dir): bool
    {
        foreach ($libraries as $library) {
            if ($library->path === $dir) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Library> $libraries
     */
    private function insideLibrary(array $libraries, string $dir): bool
    {
        foreach ($libraries as $library) {
            if (str_starts_with($dir . '/', rtrim($library->path, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    private function relative(string $root, string $path): string
    {
        return ltrim(substr($path, strlen($root)), '/');
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
