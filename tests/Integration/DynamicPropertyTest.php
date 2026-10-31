<?php

declare(strict_types=1);

namespace Refit\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Refit\Check\RectorFixer;
use Refit\Rules\PhpVersion;
use Refit\Scanner;

/**
 * Declaring a property makes __get/__isset/__unset stop running for that name, and it shows
 * up in property_exists(), get_object_vars() and json_encode() before it is ever assigned.
 */
#[Group('slow')]
final class DynamicPropertyTest extends TestCase
{
    public function testMagicReadMethodsAreNotReplacedWithDeclaredProperties(): void
    {
        $root = Scanner::makeWorkDir();
        $work = Scanner::makeWorkDir();
        $originals = [];
        try {
            foreach ($this->fixtures() as $name => $source) {
                file_put_contents($root . '/' . $name, $source);
                $originals[$name] = $source;
            }

            (new RectorFixer())->fix(
                $root,
                (int) PhpVersion::toId('8.4'),
                (int) PhpVersion::toId('7.2'),
                [],
                [],
                false,
                $work,
            );

            foreach (['UsesSet.php', 'UsesGetAndSet.php', 'ChildOfSet.php'] as $unchanged) {
                self::assertSame($originals[$unchanged], file_get_contents($root . '/' . $unchanged), $unchanged);
            }

            foreach (['UsesGet.php', 'UsesIsset.php', 'UsesUnset.php', 'ChildOfGet.php', 'ChildOfIsset.php', 'ChildOfUnset.php', 'ChildOfGrandGet.php', 'UsesTrait.php'] as $magic) {
                $source = (string) file_get_contents($root . '/' . $magic);
                self::assertStringNotContainsString('public $config', $source, $magic);
                self::assertStringContainsString('#[\AllowDynamicProperties]', $source, $magic);
            }

            $plain = (string) file_get_contents($root . '/Plain.php');
            self::assertMatchesRegularExpression('/public \$title;/', $plain);
            self::assertStringNotContainsString('AllowDynamicProperties', $plain);

            foreach (array_keys($originals) as $name) {
                require_once $root . '/' . $name;
            }

            $get = new \RefitFixture\Dynamic\UsesGet();
            self::assertFalse((new \ReflectionClass($get))->hasProperty('config'));
            self::assertFalse(property_exists($get, 'config'));
            self::assertSame('from-get', $get->config);
            self::assertSame('{}', json_encode($get));
            self::assertArrayNotHasKey('config', get_object_vars($get));

            $isset = new \RefitFixture\Dynamic\UsesIsset();
            self::assertTrue(isset($isset->config));

            $unset = new \RefitFixture\Dynamic\UsesUnset();
            unset($unset->config);
            self::assertSame(['config'], $unset->log);

            $child = new \RefitFixture\Dynamic\ChildOfGet();
            self::assertFalse((new \ReflectionClass($child))->hasProperty('config'));
            self::assertSame('from-parent', $child->config);

            $grand = new \RefitFixture\Dynamic\ChildOfGrandGet();
            self::assertSame('from-grand', $grand->config);

            $childIsset = new \RefitFixture\Dynamic\ChildOfIsset();
            self::assertTrue(isset($childIsset->config));

            $childUnset = new \RefitFixture\Dynamic\ChildOfUnset();
            unset($childUnset->config);
            self::assertSame(['config'], $childUnset->log);

            $trait = new \RefitFixture\Dynamic\UsesTrait();
            self::assertSame('from-trait', $trait->config);

            $set = new \RefitFixture\Dynamic\UsesSet();
            $set->save();
            self::assertSame(['config'], $set->seen);

            $childSet = new \RefitFixture\Dynamic\ChildOfSet();
            $childSet->save();
            self::assertSame(['config'], $childSet->seen);

            $plainObject = new \RefitFixture\Dynamic\PlainWidget();
            self::assertTrue((new \ReflectionClass($plainObject))->hasProperty('title'));
            self::assertSame('Hi', $plainObject->title);
            self::assertArrayHasKey('title', get_object_vars($plainObject));
        } finally {
            Scanner::removeDirectory($root);
            Scanner::removeDirectory($work);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fixtures(): array
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace RefitFixture\\Dynamic;\n\n";

        return [
            'UsesGet.php' => $header . <<<'PHP'
class UsesGet
{
    private $store = ['config' => 'from-get'];

    public function save(): void
    {
        $this->config = 'written';
    }

    public function __get($name)
    {
        return $this->store[$name] ?? null;
    }
}

PHP,
            'UsesIsset.php' => $header . <<<'PHP'
class UsesIsset
{
    public function save(): void
    {
        $this->config = 'written';
    }

    public function __isset($name)
    {
        return $name === 'config';
    }
}

PHP,
            'UsesUnset.php' => $header . <<<'PHP'
class UsesUnset
{
    public $log = [];

    public function save(): void
    {
        $this->config = 'written';
    }

    public function __unset($name): void
    {
        $this->log[] = $name;
    }
}

PHP,
            'UsesSet.php' => $header . <<<'PHP'
class UsesSet
{
    public $seen = [];

    public function save(): void
    {
        $this->config = 'written';
    }

    public function __set($name, $value): void
    {
        $this->seen[] = $name;
    }
}

PHP,
            'UsesGetAndSet.php' => $header . <<<'PHP'
class UsesGetAndSet
{
    private $store = [];

    public function save(): void
    {
        $this->config = 'written';
    }

    public function __get($name)
    {
        return $this->store[$name] ?? null;
    }

    public function __set($name, $value): void
    {
        $this->store[$name] = $value;
    }
}

PHP,
            'ChildOfGet.php' => $header . <<<'PHP'
class ParentWithGet
{
    private $store = ['config' => 'from-parent'];

    public function __get($name)
    {
        return $this->store[$name] ?? null;
    }
}

class ChildOfGet extends ParentWithGet
{
    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'ChildOfIsset.php' => $header . <<<'PHP'
class ParentWithIsset
{
    public function __isset($name)
    {
        return $name === 'config';
    }
}

class ChildOfIsset extends ParentWithIsset
{
    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'ChildOfUnset.php' => $header . <<<'PHP'
class ParentWithUnset
{
    public $log = [];

    public function __unset($name): void
    {
        $this->log[] = $name;
    }
}

class ChildOfUnset extends ParentWithUnset
{
    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'ChildOfSet.php' => $header . <<<'PHP'
class ParentWithSet
{
    public $seen = [];

    public function __set($name, $value): void
    {
        $this->seen[] = $name;
    }
}

class ChildOfSet extends ParentWithSet
{
    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'ChildOfGrandGet.php' => $header . <<<'PHP'
class GrandWithGet
{
    public function __get($name)
    {
        return $name === 'config' ? 'from-grand' : null;
    }
}

class MidWithGet extends GrandWithGet
{
}

class ChildOfGrandGet extends MidWithGet
{
    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'UsesTrait.php' => $header . <<<'PHP'
trait MagicGet
{
    private $store = ['config' => 'from-trait'];

    public function __get($name)
    {
        return $this->store[$name] ?? null;
    }
}

class UsesTrait
{
    use MagicGet;

    public function save(): void
    {
        $this->config = 'written';
    }
}

PHP,
            'Plain.php' => $header . <<<'PHP'
class PlainWidget
{
    public function __construct()
    {
        $this->title = 'Hi';
    }
}

PHP,
        ];
    }
}
