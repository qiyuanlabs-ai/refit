<?php

declare(strict_types=1);

namespace Refit\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Refit\Check\RectorFixer;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * PHP 8.2 deprecates creating properties that were never declared ("dynamic properties"),
 * which floods logs on old plugins and becomes an Error in PHP 9.
 *
 * Rector's own CompleteDynamicPropertiesRector is deprecated because it added typed
 * properties, which can turn a notice into a fatal "accessed before initialization".
 * This rule declares the missing properties untyped and public. That is not
 * behaviour-neutral: property_exists(), get_object_vars() and json_encode() include a
 * declared property even before it is assigned, and __get, __isset and __unset no longer
 * run for that name. If the class or an ancestor defines any of those methods, the
 * property is not declared and #[\AllowDynamicProperties] is added instead. A class or
 * ancestor that defines __set is left unchanged, because assignment already goes through
 * that method. When a parent class or a trait cannot be inspected (for example a
 * WooCommerce base class), the attribute is added as well, which is harmless on every
 * PHP version.
 */
final class DeclareDynamicPropertiesRector extends AbstractRector
{

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Declare properties that are created dynamically (deprecated in PHP 8.2)', [
            new CodeSample(
                "class Widget\n{\n    public function __construct()\n    {\n        \$this->title = 'Hi';\n    }\n}",
                "class Widget\n{\n    public \$title;\n    public function __construct()\n    {\n        \$this->title = 'Hi';\n    }\n}",
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->isAnonymous() || $node->name === null || $this->hasAttribute($node, 'AllowDynamicProperties')) {
            return null;
        }
        if ($this->hasMagicSet($node)) {
            return null;
        }

        $assigned = $this->assignedPropertyNames($node);
        if ($assigned === []) {
            return null;
        }

        $declaredHere = [];
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $declaredHere[$prop->name->toString()] = true;
            }
        }
        $constructor = $node->getMethod('__construct');
        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                if ($param->flags !== 0 && $param->var instanceof Variable && is_string($param->var->name)) {
                    $declaredHere[$param->var->name] = true;
                }
            }
        }

        $missing = array_values(array_filter($assigned, static fn (string $name): bool => !isset($declaredHere[$name])));
        if ($missing === []) {
            return null;
        }

        $inherited = $this->inheritedLookup($node);
        if ($inherited === null) {
            // Something this class builds on is outside the scanned code. We cannot tell which
            // properties it already declares, so allow dynamic properties instead.
            return $this->allowDynamic($node, $missing);
        }

        $missing = array_values(array_filter($missing, static fn (string $name): bool => !$inherited['declares']($name)));
        if ($missing === []) {
            return null;
        }

        // Declaring the property would stop __get/__isset/__unset from running for that name.
        if ($this->hasMagicRead($node) || $inherited['magicRead']) {
            return $this->allowDynamic($node, $missing);
        }

        $newProperties = [];
        foreach ($missing as $name) {
            $newProperties[] = new Property(Class_::MODIFIER_PUBLIC, [new PropertyItem($name)]);
        }
        $node->stmts = $this->insertProperties($node->stmts, $newProperties);
        $this->log($node, $missing, false);

        return $node;
    }

    /**
     * @param list<string> $properties
     */
    private function allowDynamic(Class_ $class, array $properties): Class_
    {
        $class->attrGroups[] = new AttributeGroup([new Attribute(new FullyQualified('AllowDynamicProperties'))]);
        $this->log($class, $properties, true);

        return $class;
    }

    /**
     * @param list<string> $properties
     */
    private function log(Class_ $class, array $properties, bool $attribute): void
    {
        $file = getenv(RectorFixer::DYNAMIC_PROPERTIES_LOG_ENV);
        if ($file === false || $file === '') {
            return;
        }
        $entry = [
            'file' => $this->getFile()->getFilePath(),
            'line' => $class->getStartLine(),
            'class' => (string) $this->getName($class),
            'properties' => $properties,
            'attribute' => $attribute,
        ];
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<string> property names assigned through $this, in order of first appearance
     */
    private function assignedPropertyNames(Class_ $class): array
    {
        $names = [];
        $finder = new NodeFinder();
        foreach ($class->getMethods() as $method) {
            if ($method->isStatic() || $method->stmts === null) {
                continue;
            }
            $assignments = $finder->find($method->stmts, static fn (Node $n): bool => $n instanceof Assign || $n instanceof AssignRef || $n instanceof AssignOp);
            foreach ($assignments as $assign) {
                $target = $assign->var;
                while ($target instanceof ArrayDimFetch) {
                    $target = $target->var;
                }
                if (!$target instanceof PropertyFetch || !$target->var instanceof Variable || $target->var->name !== 'this') {
                    continue;
                }
                if (!$target->name instanceof Identifier) {
                    continue; // $this->$name = ... cannot be resolved statically
                }
                $names[$target->name->toString()] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Tells whether a parent class or trait already declares a property, and whether any
     * of them reads properties through __get, __isset or __unset.
     *
     * Returns null when some parent or trait cannot be inspected.
     *
     * @return array{declares: callable(string): bool, magicRead: bool}|null
     */
    private function inheritedLookup(Class_ $class): ?array
    {
        $sources = [];
        if ($class->extends !== null) {
            $sources[] = $this->getName($class->extends);
        }
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $sources[] = $this->getName($trait);
                }
            }
        }

        $reflections = [];
        $magicRead = false;
        foreach ($sources as $name) {
            if ($name === null || !$this->reflectionProvider->hasClass($name)) {
                return null;
            }
            $reflection = $this->reflectionProvider->getClass($name);
            // __set already accepts dynamic writes. Keep the previous behaviour: do not
            // declare properties and do not add the attribute.
            if ($reflection->getName() === 'stdClass' || $reflection->hasNativeMethod('__set')) {
                return ['declares' => static fn (string $property): bool => true, 'magicRead' => false];
            }
            foreach ($reflection->getAncestors() as $ancestor) {
                if ($ancestor->getName() === 'stdClass' || $ancestor->getNativeReflection()->getAttributes('AllowDynamicProperties') !== []) {
                    return ['declares' => static fn (string $property): bool => true, 'magicRead' => false];
                }
            }
            if ($this->reflectionHasMagicRead($reflection)) {
                $magicRead = true;
            }
            $reflections[] = $reflection;
        }

        return [
            'declares' => static function (string $property) use ($reflections): bool {
                foreach ($reflections as $reflection) {
                    if ($reflection->hasNativeProperty($property)) {
                        return true;
                    }
                }

                return false;
            },
            'magicRead' => $magicRead,
        ];
    }

    private function reflectionHasMagicRead(ClassReflection $reflection): bool
    {
        $chain = [$reflection, ...$reflection->getAncestors()];
        foreach ($chain as $class) {
            foreach (['__get', '__isset', '__unset'] as $method) {
                if ($class->hasNativeMethod($method)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasMagicSet(Class_ $class): bool
    {
        return $class->getMethod('__set') instanceof ClassMethod;
    }

    private function hasMagicRead(Class_ $class): bool
    {
        foreach (['__get', '__isset', '__unset'] as $method) {
            if ($class->getMethod($method) instanceof ClassMethod) {
                return true;
            }
        }

        return false;
    }

    private function hasAttribute(Class_ $class, string $shortName): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (strcasecmp(ltrim($attr->name->toString(), '\\'), $shortName) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Node\Stmt[] $stmts
     * @param Property[] $newProperties
     * @return Node\Stmt[]
     */
    private function insertProperties(array $stmts, array $newProperties): array
    {
        $position = 0;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof TraitUse || $stmt instanceof Node\Stmt\ClassConst || $stmt instanceof Property) {
                $position = $i + 1;
            }
        }
        array_splice($stmts, $position, 0, $newProperties);

        return $stmts;
    }
}
