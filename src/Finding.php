<?php

declare(strict_types=1);

namespace Refit;

final class Finding
{
    /** Will fail on the target PHP: fatal error, file won't load, or the feature is gone. */
    public const BREAKS = 'breaks';
    /** Still works on the target PHP but logs deprecation notices; fails in a later version. */
    public const DEPRECATED = 'deprecated';
    /** Can fail on the target PHP depending on input or data; a person should look at it. */
    public const REVIEW = 'review';

    public const SEVERITY_ORDER = [self::BREAKS => 0, self::REVIEW => 1, self::DEPRECATED => 2];

    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $source,
        public bool $fixable = false,
        public ?string $library = null,
        public ?string $since = null,
        public bool $preexisting = false,
    ) {
    }

    /**
     * The newest PHP version a message names, e.g. "deprecated since PHP 7.2 and removed since
     * PHP 8.0" gives 8.0: the version from which the code stops working as written.
     */
    public static function versionInMessage(string $message): ?string
    {
        if (!preg_match_all('/(?:PHP|since) (\d+\.\d+)/', $message, $m)) {
            return null;
        }
        usort($m[1], 'version_compare');

        return end($m[1]) ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'source' => $this->source,
            'fixable' => $this->fixable,
            'library' => $this->library,
            'since' => $this->since,
            'preexisting' => $this->preexisting,
        ];
    }
}
