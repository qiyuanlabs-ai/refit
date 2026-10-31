<?php

declare(strict_types=1);

namespace Refit;

use Refit\Project\Project;

final class Report
{
    /**
     * @param list<Finding> $findings
     * @param array<string, string> $tools tool name => version
     */
    public function __construct(
        public readonly Project $project,
        public readonly string $targetPhp,
        public readonly string $minPhp,
        public readonly string $minPhpSource,
        public readonly string $lintPhp,
        public readonly array $findings,
        public readonly int $fixableFileCount,
        public readonly array $tools,
        public readonly \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function own(?string $severity = null): array
    {
        return array_values(array_filter($this->filter(false, $severity), static fn (Finding $f): bool => !$f->preexisting));
    }

    /**
     * Problems in the project's own code that already exist on its minimum PHP version.
     *
     * @return list<Finding>
     */
    public function preexisting(): array
    {
        return array_values(array_filter($this->filter(false, null), static fn (Finding $f): bool => $f->preexisting));
    }

    /**
     * @return list<Finding>
     */
    public function bundled(?string $severity = null): array
    {
        return $this->filter(true, $severity);
    }

    public function fixableCount(bool $bundled = false): int
    {
        $findings = $bundled ? $this->bundled() : $this->own();

        return count(array_filter($findings, static fn (Finding $f): bool => $f->fixable));
    }

    /**
     * One-line verdict a client can read.
     */
    public function verdict(): string
    {
        $old = count($this->preexisting());
        $suffix = $old === 0 ? '' : sprintf(' %d more %s already %s on PHP %s.', $old, $old === 1 ? 'problem' : 'problems', $old === 1 ? 'exists' : 'exist', $this->minPhp);

        return $this->mainVerdict() . $suffix;
    }

    private function mainVerdict(): string
    {
        $breaks = count($this->own(Finding::BREAKS));
        $review = count($this->own(Finding::REVIEW));
        $deprecated = count($this->own(Finding::DEPRECATED));

        if ($breaks > 0) {
            return sprintf('Not ready for PHP %s: %d %s will fail on PHP %s.', $this->targetPhp, $breaks, $breaks === 1 ? 'problem' : 'problems', $this->targetPhp);
        }
        if ($review > 0) {
            return sprintf('Probably runs on PHP %s, but %d %s can fail with unexpected input and should be checked.', $this->targetPhp, $review, $review === 1 ? 'spot' : 'spots');
        }
        if ($deprecated > 0) {
            return sprintf('Runs on PHP %s, with %d deprecation %s that will fill the error log and break in a later PHP version.', $this->targetPhp, $deprecated, $deprecated === 1 ? 'warning' : 'warnings');
        }

        return sprintf('No PHP %s compatibility problems found by the automated checks.', $this->targetPhp);
    }

    /**
     * Findings grouped by message code, worst first, then by count.
     *
     * @param list<Finding> $findings
     * @return list<array{code: string, severity: string, findings: list<Finding>}>
     */
    public static function group(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $key = Explanations::groupKey($finding);
            $groups[$key] ??= ['code' => $key, 'severity' => $finding->severity, 'findings' => []];
            $groups[$key]['findings'][] = $finding;
            if (Finding::SEVERITY_ORDER[$finding->severity] < Finding::SEVERITY_ORDER[$groups[$key]['severity']]) {
                $groups[$key]['severity'] = $finding->severity;
            }
        }
        usort($groups, static fn (array $a, array $b): int => [Finding::SEVERITY_ORDER[$a['severity']], -count($a['findings'])]
            <=> [Finding::SEVERITY_ORDER[$b['severity']], -count($b['findings'])]);

        return array_values($groups);
    }

    /**
     * @return list<Finding>
     */
    private function filter(bool $bundled, ?string $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $f): bool => ($f->library !== null) === $bundled && ($severity === null || $f->severity === $severity),
        ));
    }
}
