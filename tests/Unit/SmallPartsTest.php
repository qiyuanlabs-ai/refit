<?php

declare(strict_types=1);

namespace Refit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Refit\Check\IndentRepair;
use Refit\Check\RectorResult;
use Refit\Finding;
use Refit\Rules\PhpVersion;

final class SmallPartsTest extends TestCase
{
    public function testPhpVersion(): void
    {
        self::assertSame(80300, PhpVersion::toId('8.3'));
        self::assertSame(70400, PhpVersion::toId('7.4.33'));
        self::assertNull(PhpVersion::toId('latest'));
        self::assertSame('8.4', PhpVersion::toString(80400));
    }

    public function testVersionInMessageTakesTheNewestVersion(): void
    {
        self::assertSame('8.0', Finding::versionInMessage('Function each() is deprecated since PHP 7.2 and removed since PHP 8.0; Use a foreach loop'));
        self::assertSame('8.4', Finding::versionInMessage('Passing E_USER_ERROR to trigger_error() is deprecated since 8.4.'));
        self::assertNull(Finding::versionInMessage('No version here'));
    }

    public function testRectorResultMapsDiffLinesToOriginalLines(): void
    {
        $result = new RectorResult();
        $diff = "--- Original\n+++ New\n@@ -4,4 +4,4 @@\n {\n-    a();\n+    b();\n     c();\n";
        $result->addDiff('x.php', $diff, ['Rector\\Some\\FooRector'], [['rector' => 'Rector\\Some\\FooRector', 'line' => 5]]);

        self::assertTrue($result->touches('x.php', 5));
        self::assertFalse($result->touches('x.php', 4));
        self::assertSame(['FooRector'], $result->rules['x.php']);
        self::assertSame([['rule' => 'FooRector', 'line' => 5]], $result->changes['x.php']);
    }

    public function testIndentRepairFixesTabsRectorMultiplied(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'refit');
        file_put_contents($file, "<?php\nclass A {\n\tpublic \$a;\n\t\t\t\tpublic \$b;\n\t\t\t\t/**\n\t * Doc\n\t */\n\tfunction f() {}\n}\n");
        $diff = "--- Original\n+++ New\n@@ -2,4 +2,5 @@\n class A {\n \tpublic \$a;\n+\t\t\t\tpublic \$b;\n-\t/**\n+\t\t\t\t/**\n \t * Doc\n";

        IndentRepair::repair($file, $diff);

        self::assertSame("<?php\nclass A {\n\tpublic \$a;\n\tpublic \$b;\n\t/**\n\t * Doc\n\t */\n\tfunction f() {}\n}\n", file_get_contents($file));
        unlink($file);
    }
}
