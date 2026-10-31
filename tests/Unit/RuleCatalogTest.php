<?php

declare(strict_types=1);

namespace Refit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refit\Rector\DeclareDynamicPropertiesRector;
use Refit\Rector\ImplodeArgumentOrderRector;
use Refit\Rules\RuleCatalog;

final class RuleCatalogTest extends TestCase
{
    public function testOnlyIncludesRulesUpToTheTargetVersion(): void
    {
        $rules = RuleCatalog::rulesFor(80000, 70400);

        self::assertContains(ImplodeArgumentOrderRector::class, $rules);
        self::assertNotContains(DeclareDynamicPropertiesRector::class, $rules, 'dynamic properties are a PHP 8.2 issue');
        self::assertContains(DeclareDynamicPropertiesRector::class, RuleCatalog::rulesFor(80200, 70400));
    }

    public function testNeverUsesSyntaxNewerThanTheMinimumVersion(): void
    {
        $nullable = 'Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector';

        self::assertNotContains($nullable, RuleCatalog::rulesFor(80400, 70000), '?Type needs PHP 7.1');
        self::assertContains($nullable, RuleCatalog::rulesFor(80400, 70100));
    }

    public function testNoisyRulesAreOptIn(): void
    {
        $nullCasts = 'Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector';

        self::assertNotContains($nullCasts, RuleCatalog::rulesFor(80400, 70400));
        self::assertContains($nullCasts, RuleCatalog::rulesFor(80400, 70400, true));
    }

    public function testRulesThatBrokeRealPluginsAreNeverUsed(): void
    {
        $all = RuleCatalog::rulesFor(99999, 99999, true);
        foreach (['SensitiveConstantNameRector', 'AssignArrayToStringRector', 'RemoveExtraParametersRector', 'WrapVariableVariableNameInCurlyBracesRector', 'StringifyStrNeedlesRector', 'CompleteDynamicPropertiesRector'] as $rule) {
            foreach ($all as $class) {
                self::assertStringEndsNotWith('\\' . $rule, $class);
            }
        }
    }

    public function testEveryRuleClassExists(): void
    {
        foreach (RuleCatalog::rulesFor(99999, 99999, true) as $class) {
            self::assertFileExists($this->classFile($class), $class);
        }
    }

    public function testSince(): void
    {
        self::assertSame('8.2', RuleCatalog::since(DeclareDynamicPropertiesRector::class));
        self::assertNull(RuleCatalog::since('Nope'));
    }

    private function classFile(string $class): string
    {
        if (str_starts_with($class, 'Refit\\')) {
            return dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        }
        $parts = explode('\\', $class);
        array_shift($parts); // Rector
        $group = array_shift($parts);

        return dirname(__DIR__, 2) . '/vendor/rector/rector/rules/' . $group . '/' . implode('/', $parts) . '.php';
    }
}
