<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 闭包值：保存 AST 与 use 捕获的变量环境。
 */
final class ClosureValue extends Value
{
    /**
     * @param array<string, Value> $captured
     */
    public function __construct(
        public readonly Closure|ArrowFunction $node,
        public readonly array $captured = [],
        ?Taint $taint = null,
    ) {
        parent::__construct($taint);
    }

    public function type(): Type
    {
        return Type::Object;
    }

    public function describe(): string
    {
        return 'Closure(' . ($this->node->getStartLine() > 0 ? 'line ' . $this->node->getStartLine() : '?') . ')';
    }

    public function toExpr(): Expr
    {
        return new VarExpr('<closure>', Type::Object);
    }

    public function merge(Value $other): Value
    {
        if ($other instanceof self && $other->node === $this->node) {
            return $this;
        }

        return new UnknownValue(ValueFactory::freshName('merged-closure'), $this->taint->union($other->taint));
    }
}
