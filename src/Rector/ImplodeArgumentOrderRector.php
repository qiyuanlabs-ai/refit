<?php

declare(strict_types=1);

namespace Refit\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * implode($pieces, $glue) was removed in PHP 8.0 and now throws a TypeError.
 * Swaps the arguments when the second one is known to be a string and the first is not.
 */
final class ImplodeArgumentOrderRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Put implode()/join() arguments in the order PHP 8 requires', [
            new CodeSample('implode($ids, \',\');', 'implode(\',\', $ids);'),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    /**
     * @param FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$this->isNames($node, ['implode', 'join']) || $node->isFirstClassCallable()) {
            return null;
        }
        $args = $node->args;
        if (count($args) !== 2 || !$args[0] instanceof Arg || !$args[1] instanceof Arg) {
            return null;
        }
        if ($args[0]->unpack || $args[1]->unpack || $args[0]->name !== null || $args[1]->name !== null) {
            return null;
        }
        if (!$this->getType($args[1]->value)->isString()->yes()) {
            return null;
        }
        if ($this->getType($args[0]->value)->isString()->yes()) {
            return null;
        }
        $node->args = [$args[1], $args[0]];

        return $node;
    }
}
