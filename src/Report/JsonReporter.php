<?php

declare(strict_types=1);

namespace Refit\Report;

use Refit\Finding;
use Refit\Report;

final class JsonReporter
{
    public function render(Report $report): string
    {
        $count = static fn (array $findings): array => [
            'breaks' => count(array_filter($findings, static fn (Finding $f): bool => $f->severity === Finding::BREAKS)),
            'review' => count(array_filter($findings, static fn (Finding $f): bool => $f->severity === Finding::REVIEW)),
            'deprecated' => count(array_filter($findings, static fn (Finding $f): bool => $f->severity === Finding::DEPRECATED)),
            'fixable' => count(array_filter($findings, static fn (Finding $f): bool => $f->fixable)),
        ];

        return json_encode([
            'project' => [
                'name' => $report->project->name,
                'type' => $report->project->type,
                'version' => $report->project->version,
                'php_files' => count($report->project->phpFiles),
            ],
            'target_php' => $report->targetPhp,
            'min_php' => $report->minPhp,
            'min_php_source' => $report->minPhpSource,
            'lint_php' => $report->lintPhp,
            'verdict' => $report->verdict(),
            'summary' => [
                'own_code' => $count($report->own()),
                'already_on_min_php' => $count($report->preexisting()),
                'bundled_libraries' => $count($report->bundled()),
            ],
            'libraries' => LibrarySummary::rows($report),
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $report->findings),
            'tools' => $report->tools,
            'generated_at' => $report->generatedAt->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
