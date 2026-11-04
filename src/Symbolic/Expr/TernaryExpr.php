<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 三元表达式 cond ? a : b。
 */
final class TernaryExpr extends Expr
{
    public function __construct(
        public readonly Expr $cond,
        public readonly Expr $ifTrue,
        public readonly Expr $ifFalse,
    ) {
    }

    public function type(): Type
    {
        $left = $this->ifTrue->type();
        $right = $this->ifFalse->type();

        return $left === $right ? $left : Type::Mixed;
    }

    public function toString(): string
    {
        return '(' . $this->cond->toString() . ' ? ' . $this->ifTrue->toString() . ' : ' . $this->ifFalse->toString() . ')';
    }

    public function canonical(): string
    {
        return 't:(' . $this->cond->canonical() . '?' . $this->ifTrue->canonical() . ':' . $this->ifFalse->canonical() . ')';
    }

    public function children(): array
    {
        return [$this->cond, $this->ifTrue, $this->ifFalse];
    }

    public function isPure(): bool
    {
        return $this->cond->isPure() && $this->ifTrue->isPure() && $this->ifFalse->isPure();
    }

    public function evaluate(array $env = []): mixed
    {
        $cond = $this->cond->evaluate($env);
        if ($cond === Unknown::value()) {
            return Unknown::value();
        }

        return $cond ? $this->ifTrue->evaluate($env) : $this->ifFalse->evaluate($env);
    }
}
