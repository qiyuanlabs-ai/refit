<?php

declare(strict_types=1);

namespace Refit\Standards\Refit\Sniffs\DateTime;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Since PHP 8.3, DateTime::modify() and DateTimeImmutable::modify() throw a
 * DateMalformedStringException for strings they cannot parse. Before, they returned false
 * with a warning, so code that checks for false now dies with an uncaught exception.
 *
 * Flags ->modify() calls outside a try block, in files that work with DateTime objects, whose
 * argument is built at runtime (from a variable, a setting or user input).
 */
final class UnguardedModifySniff implements Sniff
{
    /** Whether the highest PHP version under test is 8.3 or later. */
    private ?bool $applies = null;

    public function register(): array
    {
        return [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        if (!$this->appliesToTarget($phpcsFile)) {
            return $phpcsFile->numTokens;
        }
        $tokens = $phpcsFile->getTokens();
        $name = $phpcsFile->findNext(T_WHITESPACE, $stackPtr + 1, null, true);
        if ($name === false || $tokens[$name]['code'] !== T_STRING || strtolower($tokens[$name]['content']) !== 'modify') {
            return;
        }
        $open = $phpcsFile->findNext(T_WHITESPACE, $name + 1, null, true);
        if ($open === false || $tokens[$open]['code'] !== T_OPEN_PARENTHESIS) {
            return;
        }
        if (!$this->fileUsesDateTime($phpcsFile)) {
            return $phpcsFile->numTokens;
        }
        if ($this->onlyLiteralArgument($phpcsFile, $open)) {
            return; // a fixed string like '+1 day' either always works or always fails in testing
        }
        foreach ($tokens[$stackPtr]['conditions'] ?? [] as $code) {
            if ($code === T_TRY) {
                return;
            }
        }

        $phpcsFile->addWarning(
            'DateTime::modify() throws DateMalformedStringException on PHP 8.3+ when the string cannot be parsed; it used to return false. Wrap the call in try/catch.',
            $name,
            'Found'
        );
    }

    private function onlyLiteralArgument(File $phpcsFile, int $open): bool
    {
        $tokens = $phpcsFile->getTokens();
        $close = $tokens[$open]['parenthesis_closer'] ?? null;
        if ($close === null) {
            return false;
        }
        for ($i = $open + 1; $i < $close; $i++) {
            if (!in_array($tokens[$i]['code'], [T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE, T_STRING_CONCAT, T_COMMENT], true)) {
                return false;
            }
        }

        return true;
    }

    private function appliesToTarget(File $phpcsFile): bool
    {
        if ($this->applies === null) {
            $parts = explode('-', (string) \PHP_CodeSniffer\Config::getConfigData('testVersion'));
            $max = trim(end($parts));
            $this->applies = $max === '' || version_compare($max, '8.3', '>=');
        }

        return $this->applies;
    }

    private function fileUsesDateTime(File $phpcsFile): bool
    {
        static $cache = [];
        $key = $phpcsFile->getFilename();
        if (!isset($cache[$key])) {
            $content = $phpcsFile->getTokensAsString(0, $phpcsFile->numTokens);
            $cache[$key] = (bool) preg_match('/\b(DateTime(Immutable|Interface)?|date_create(_immutable)?|Carbon)\b/i', $content);
        }

        return $cache[$key];
    }
}
