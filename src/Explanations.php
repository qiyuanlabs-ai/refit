<?php

declare(strict_types=1);

namespace Refit;

/**
 * Plain-English titles and advice for the report, written for the person who pays for the
 * upgrade rather than the person who does it.
 */
final class Explanations
{
    /**
     * Code (or code prefix) => [title, what it means, how to fix].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const KNOWN = [
        'Lint.' => [
            'File does not compile',
            'PHP refuses to load this file at all. Anything that includes it, often the whole plugin, stops with a fatal error.',
            'Fix the syntax error shown. It is usually old syntax that newer PHP removed.',
        ],
        'PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess' => [
            'Curly brace string and array access ($str{0})',
            'Removed in PHP 8.0. The file no longer compiles.',
            'Use square brackets: $str[0]. Refit fixes this automatically.',
        ],
        'PHPCompatibility.ParameterValues.RemovedImplodeFlexibleParamOrder' => [
            'implode() with the arguments in the old order',
            'Since PHP 8.0, implode($array, $glue) throws a TypeError, which shows up as a critical error on the page that runs it.',
            'Swap the arguments: implode($glue, $array). Refit fixes this automatically when it can tell which argument is the glue.',
        ],
        'PHPCompatibility.FunctionUse.RemovedFunctions.create_function' => [
            'create_function()',
            'Removed in PHP 8.0. Calling it is a fatal error.',
            'Replace it with an anonymous function. Refit fixes this automatically.',
        ],
        'PHPCompatibility.FunctionUse.RemovedFunctions.each' => [
            'each()',
            'Removed in PHP 8.0. Calling it is a fatal error.',
            'Rewrite the loop with foreach. Refit fixes the common while/list/each pattern automatically.',
        ],
        'PHPCompatibility.Constants.RemovedConstants.filter_sanitize_string' => [
            'FILTER_SANITIZE_STRING',
            'Deprecated in PHP 8.1 and planned for removal. Every use writes a deprecation notice to the log.',
            'In WordPress, use sanitize_text_field( wp_unslash( $value ) ). Elsewhere, use htmlspecialchars() when printing. Not fixed automatically because the behaviour differs.',
        ],
        'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds' => [
            '"${var}" inside strings',
            'Deprecated in PHP 8.2 and will stop working in PHP 9.',
            'Write "{$var}" instead. Refit fixes this automatically.',
        ],
        'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam' => [
            'Parameters that are implicitly nullable (Type $x = null)',
            'Deprecated in PHP 8.4. Each declaration logs a deprecation notice when the file loads.',
            'Write ?Type $x = null. Refit fixes this automatically (needs PHP 7.1 or later).',
        ],
        'PHPCompatibility.FunctionDeclarations.RemovedOptionalBeforeRequiredParam' => [
            'Optional parameter declared before a required one',
            'Deprecated in PHP 8.0. The default value is silently ignored, and a notice is logged.',
            'Remove the default value or reorder the parameters and update the callers.',
        ],
        'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_' => [
            'utf8_encode() / utf8_decode()',
            'Deprecated in PHP 8.2 and will be removed.',
            'Use mb_convert_encoding($text, \'UTF-8\', \'ISO-8859-1\'). Run refit fix --all-rules to apply this.',
        ],
        'PHPCompatibility.FunctionUse.RemovedFunctions.money_format' => [
            'money_format()',
            'Removed in PHP 8.0. Calling it is a fatal error.',
            'Use number_format() or NumberFormatter. Refit replaces it with number_format() automatically.',
        ],
        'Refit.DateTime.UnguardedModify' => [
            'DateTime::modify() without error handling',
            'Since PHP 8.3, modify() throws DateMalformedStringException on a date string it cannot parse, where it used to return false. Bad input (often from a URL or a saved setting) now crashes the page.',
            'Wrap the call in try { ... } catch (\Exception $e) { ... } and handle the failure there.',
        ],
        'Refit.DynamicProperty' => [
            'Properties created without being declared ("dynamic properties")',
            'Deprecated in PHP 8.2. Every such assignment logs a deprecation notice, which floods debug logs; PHP 9 is expected to make it an error.',
            'Declare the properties in the class. Refit adds them automatically as plain public properties, or adds #[\AllowDynamicProperties] when the parent class lives outside the scanned code.',
        ],
        'Refit.RectorError' => [
            'Automatic fixer could not process the file',
            'Usually means the file uses syntax that modern PHP cannot parse, or references code that is not part of the scan.',
            'Check this file by hand.',
        ],
        'Refit.Autofix' => [
            'Other changes the automatic fixer can make',
            'Code patterns that changed meaning or were removed in newer PHP versions.',
            'Run refit fix and review the diff.',
        ],
    ];

    public static function groupKey(Finding $finding): string
    {
        $code = $finding->code;
        if (str_starts_with($code, 'Lint.')) {
            return 'Lint.';
        }
        foreach (array_keys(self::KNOWN) as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return $prefix;
            }
        }

        // PHPCompatibility codes end in a variant (Found, Deprecated80, Removed...); group by the rest.
        return (string) preg_replace('/\.(?:Found|Deprecated\d*|Removed\d*|DeprecatedRemoved|Discouraged)$/', '', $code);
    }

    public static function title(string $groupKey, Finding $example): string
    {
        if (isset(self::KNOWN[$groupKey])) {
            return self::KNOWN[$groupKey][0];
        }
        $message = (string) preg_replace('/\s*(?:;\s*)?Found[^:]*:.*$/s', '', $example->message);

        return rtrim($message, '. ');
    }

    public static function meaning(string $groupKey, string $severity, string $targetPhp): string
    {
        if (isset(self::KNOWN[$groupKey])) {
            return self::KNOWN[$groupKey][1];
        }

        return match ($severity) {
            Finding::BREAKS => sprintf('Fails on PHP %s: a fatal error or a feature that no longer exists. Visitors usually see a "critical error" page where this code runs.', $targetPhp),
            Finding::REVIEW => 'Can fail depending on the data it gets. Needs a person to check.',
            default => 'Still works, but logs a deprecation notice each time it runs and is expected to break in a later PHP version.',
        };
    }

    public static function fix(string $groupKey): ?string
    {
        return self::KNOWN[$groupKey][2] ?? null;
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            Finding::BREAKS => 'Breaks',
            Finding::REVIEW => 'Check',
            default => 'Deprecated',
        };
    }
}
