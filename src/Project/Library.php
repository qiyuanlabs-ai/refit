<?php

declare(strict_types=1);

namespace Refit\Project;

final class Library
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly ?string $version = null,
    ) {
    }
}
