<?php

declare(strict_types=1);

namespace Refit\Rules;

use Refit\Rector\DeclareDynamicPropertiesRector;
use Refit\Rector\ImplodeArgumentOrderRector;

/**
 * The Rector rules Refit applies.
 *
 * Rector's own "PHP sets" also modernize code (match, readonly, str_contains...), which
 * breaks projects that still support older PHP versions. Refit only uses rules that remove
 * a breakage, and only when the rewritten code still runs on the project's minimum version.
 *
 * Left out on purpose, after testing on real WordPress plugins:
 * - SensitiveConstantNameRector upper-cases constants it cannot find a define() for, which
 *   breaks mixed-case constants defined in another file.
 * - AssignArrayToStringRector turns $x = '' into $x = [] even when $x is later used as a string.
 * - RemoveExtraParametersRector drops arguments that user functions may read with func_get_args().
 * - WrapVariableVariableNameInCurlyBracesRector restores PHP 5 semantics, which changes code
 *   that has been running on PHP 7 since.
 * - StringifyStrNeedlesRector only adds (string) casts that PHP 8 already implies.
 */
final class RuleCatalog
{
    /**
     * class => [breaks since (target PHP id), output needs at least (min PHP id), safe by default]
     *
     * @var array<class-string, array{0: int, 1: int, 2: bool}>
     */
    private const RULES = [
        // Removed in PHP 7.0
        \Rector\Php55\Rector\FuncCall\PregReplaceEModifierRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\Assign\ListSplitStringRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\FuncCall\RenameMktimeWithoutArgsToTimeRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\FuncCall\CallUserMethodRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\FuncCall\EregToPregMatchRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\Break_\BreakNotInLoopOrSwitchToReturnRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\List_\EmptyListRector::class => [70000, 50300, true],
        \Rector\Php70\Rector\Switch_\ReduceMultipleDefaultSwitchRector::class => [70000, 50300, true],
        // Deprecated in 7.0, removed (fatal) in 8.0
        \Rector\Php70\Rector\ClassMethod\Php4ConstructorRector::class => [70000, 50300, true],
        // PHP 7.1
        \Rector\Php71\Rector\BinaryOp\BinaryOpBetweenNumberAndStringRector::class => [70100, 50300, true],
        // Deprecated in 7.2, removed in 8.0
        \Rector\Php72\Rector\Assign\ReplaceEachAssignmentWithKeyCurrentRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\Assign\ListEachRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\While_\WhileEachToForeachRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\Unset_\UnsetCastRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\FuncCall\StringifyDefineRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\FuncCall\CreateFunctionToAnonymousFunctionRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\FuncCall\StringsAssertNakedRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\FuncCall\GetClassOnNullRector::class => [70200, 50300, true],
        \Rector\Php72\Rector\FuncCall\ParseStrWithResultArgumentRector::class => [70200, 50300, true],
        // PHP 7.3
        \Rector\Php73\Rector\FuncCall\RegexDashEscapeRector::class => [70300, 50300, true],
        \Rector\Php73\Rector\FuncCall\SensitiveDefineRector::class => [70300, 50300, true],
        \Rector\Php73\Rector\String_\SensitiveHereNowDocRector::class => [70300, 50300, true],
        // Deprecated in 7.4, removed in 8.0
        \Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\ArrayDimFetch\CurlyToSquareBracketArrayStringRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\StaticCall\ExportToReflectionFunctionRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\MbStrrposEncodingArgumentPositionRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\MoneyFormatToNumberFormatRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\HebrevcToNl2brHebrevRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\RestoreIncludePathToIniRestoreRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\ArrayKeyExistsOnPropertyRector::class => [70400, 50300, true],
        \Rector\Php74\Rector\FuncCall\FilterVarToAddSlashesRector::class => [70400, 50300, true],
        // PHP 8.0
        ImplodeArgumentOrderRector::class => [80000, 50300, true],
        \Rector\Php80\Rector\FuncCall\GetHeadersArgumentFormatRector::class => [80000, 50300, true],
        \Rector\Php80\Rector\FuncCall\VersionCompareFuncCallArgumentRector::class => [80000, 50300, true],
        \Rector\Php80\Rector\ClassMethod\SetStateToStaticRector::class => [80000, 50300, true],
        \Rector\Php80\Rector\ClassMethod\FinalPrivateToPrivateVisibilityRector::class => [80000, 50300, true],
        \Rector\Php80\Rector\ClassMethod\AddParamBasedOnParentClassMethodRector::class => [80000, 50300, true],
        // PHP 8.1: casts every argument that might be null. Correct but noisy, so opt-in.
        \Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class => [80100, 50300, false],
        \Rector\Php81\Rector\FuncCall\NullToStrictIntPregSlitFuncCallLimitArgRector::class => [80100, 50300, false],
        // PHP 8.2
        DeclareDynamicPropertiesRector::class => [80200, 50300, true],
        \Rector\Php82\Rector\Encapsed\VariableInStringInterpolationFixerRector::class => [80200, 50300, true],
        \Rector\Php82\Rector\New_\FilesystemIteratorSkipDotsRector::class => [80200, 50300, true],
        \Rector\Php82\Rector\FuncCall\Utf8DecodeEncodeToMbConvertEncodingRector::class => [80200, 50300, false],
        // PHP 8.3
        \Rector\Php83\Rector\FuncCall\CombineHostPortLdapUriRector::class => [80300, 50300, true],
        \Rector\Php83\Rector\FuncCall\RemoveGetClassGetParentClassNoArgsRector::class => [80300, 50500, true],
        // PHP 8.4
        \Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class => [80400, 70100, true],
        \Rector\Php84\Rector\FuncCall\AddEscapeArgumentRector::class => [80400, 50300, true],
        // PHP 8.5
        \Rector\Php85\Rector\ShellExec\ShellExecFunctionCallOverBackticksRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\FuncCall\OrdSingleByteRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\FuncCall\ChrArgModuloRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\FuncCall\RemoveFinfoBufferContextArgRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\FuncCall\ArrayKeyExistsNullToEmptyStringRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\ClassMethod\NullDebugInfoReturnRector::class => [80500, 50300, true],
        \Rector\Php85\Rector\Switch_\ColonAfterSwitchCaseRector::class => [80500, 50300, true],
    ];

    /**
     * @return list<class-string>
     */
    public static function rulesFor(int $targetPhp, int $minPhp, bool $includeOptIn = false): array
    {
        $rules = [];
        foreach (self::RULES as $class => [$since, $needs, $default]) {
            if ($since > $targetPhp || $needs > $minPhp) {
                continue;
            }
            if (!$default && !$includeOptIn) {
                continue;
            }
            $rules[] = $class;
        }

        return $rules;
    }

    /**
     * The PHP version in which the problem a rule fixes starts to bite, e.g. "8.2".
     */
    public static function since(string $class): ?string
    {
        $since = self::RULES[$class][0] ?? null;

        return $since === null ? null : PhpVersion::toString($since);
    }
}
