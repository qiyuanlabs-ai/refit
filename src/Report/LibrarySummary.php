<?php

declare(strict_types=1);

namespace Refit\Report;

use Refit\Finding;
use Refit\Report;

final class LibrarySummary
{
    /**
     * Bundled libraries that have findings, worst first.
     *
     * @return list<array{name: string, version: ?string, path: string, breaks: int, deprecated: int, review: int}>
     */
    public static function rows(Report $report): array
    {
        $rows = [];
        foreach ($report->project->libraries as $library) {
            $rows[$library->name] ??= ['name' => $library->name, 'version' => $library->version, 'path' => $library->path, 'breaks' => 0, 'deprecated' => 0, 'review' => 0];
        }
        foreach ($report->bundled() as $finding) {
            $name = (string) $finding->library;
            $rows[$name] ??= ['name' => $name, 'version' => null, 'path' => '', 'breaks' => 0, 'deprecated' => 0, 'review' => 0];
            $key = match ($finding->severity) {
                Finding::BREAKS => 'breaks',
                Finding::REVIEW => 'review',
                default => 'deprecated',
            };
            $rows[$name][$key]++;
        }
        $rows = array_filter($rows, static fn (array $r): bool => $r['breaks'] + $r['deprecated'] + $r['review'] > 0);
        usort($rows, static fn (array $a, array $b): int => [$b['breaks'], $b['review'], $b['deprecated']] <=> [$a['breaks'], $a['review'], $a['deprecated']]);

        return array_values($rows);
    }
}
