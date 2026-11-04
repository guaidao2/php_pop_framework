<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 标量值：一个符号表达式 + 污点集合。
 */
class ScalarValue extends Value
{
    public function __construct(
        public readonly Expr $expr,
        ?Taint $taint = null,
    ) {
        parent::__construct($taint);
    }

    public function type(): Type
    {
        return $this->expr->type();
    }

    public function describe(): string
    {
        return $this->expr->toString();
    }

    public function toExpr(): Expr
    {
        return $this->expr;
    }

    public function merge(Value $other): Value
    {
        if ($other instanceof ScalarValue && $this->expr->equals($other->expr)) {
            return new ScalarValue($this->expr, $this->taint->union($other->taint));
        }

        return new UnknownValue(ValueFactory::freshName('merged'), $this->taint->union($other->taint));
    }
}
