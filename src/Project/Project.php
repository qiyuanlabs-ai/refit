<?php

declare(strict_types=1);

namespace Refit\Project;

final class Project
{
    public const WORDPRESS_PLUGIN = 'WordPress plugin';
    public const WORDPRESS_THEME = 'WordPress theme';
    public const COMPOSER = 'Composer project';
    public const PLAIN = 'PHP project';

    /**
     * @param list<string> $phpFiles paths relative to $root
     * @param list<Library> $libraries
     * @param list<string> $excluded directory names skipped everywhere in the project
     */
    public function __construct(
        public readonly string $root,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $declaredMinPhp,
        public readonly array $phpFiles,
        public readonly array $libraries,
        public readonly array $excluded = [],
    ) {
    }

    public function libraryFor(string $relativeFile): ?Library
    {
        $best = null;
        foreach ($this->libraries as $library) {
            if (str_starts_with($relativeFile, rtrim($library->path, '/') . '/')
                && ($best === null || strlen($library->path) > strlen($best->path))) {
                $best = $library;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    public function ownFiles(): array
    {
        return array_values(array_filter($this->phpFiles, fn (string $f): bool => $this->libraryFor($f) === null));
    }

    public function isWordPress(): bool
    {
        return $this->type === self::WORDPRESS_PLUGIN || $this->type === self::WORDPRESS_THEME;
    }
}
