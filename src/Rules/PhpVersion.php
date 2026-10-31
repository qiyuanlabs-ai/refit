<?php

declare(strict_types=1);

namespace Refit\Rules;

final class PhpVersion
{
    public const SUPPORTED_TARGETS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    /**
     * "8.3" => 80300, "7.4.33" => 70400. Returns null for anything that is not a PHP version.
     */
    public static function toId(string $version): ?int
    {
        if (!preg_match('/^\s*(\d+)\.(\d+)/', $version, $m)) {
            return null;
        }

        return (int) $m[1] * 10000 + (int) $m[2] * 100;
    }

    public static function toString(int $id): string
    {
        return intdiv($id, 10000) . '.' . intdiv($id % 10000, 100);
    }
}
