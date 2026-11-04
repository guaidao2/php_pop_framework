<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 一元表达式与类型转换。
 */
final class UnaryExpr extends Expr
{
    private Type $type;

    public function __construct(
        public readonly string $op,
        public readonly Expr $operand,
    ) {
        $this->type = match ($op) {
            '!' => Type::Bool,
            '~' => Type::Int,
            '(int)' => Type::Int,
            '(float)' => Type::Float,
            '(string)' => Type::String,
            '(bool)' => Type::Bool,
            '(array)' => Type::Array,
            '(object)' => Type::Object,
            '(unset)' => Type::Null,
            default => $operand->type()->isNumeric() ? $operand->type() : Type::Mixed,
        };
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function toString(): string
    {
        if (str_starts_with($this->op, '(')) {
            return $this->op . $this->operand->toString();
        }

        return $this->op . '(' . $this->operand->toString() . ')';
    }

    public function canonical(): string
    {
        return 'u:' . $this->op . '(' . $this->operand->canonical() . ')';
    }

    public function children(): array
    {
        return [$this->operand];
    }

    public function isPure(): bool
    {
        return $this->operand->isPure();
    }

    public function evaluate(array $env = []): mixed
    {
        $value = $this->operand->evaluate($env);
        if ($value === Unknown::value()) {
            return Unknown::value();
        }

        try {
            return match ($this->op) {
                '!' => !$value,
                '-' => -$value,
                '+' => +$value,
                '~' => ~$value,
                '(int)' => (int) $value,
                '(float)' => (float) $value,
                '(string)' => BinaryExpr::stringify($value),
                '(bool)' => (bool) $value,
                '(array)' => (array) $value,
                '(object)' => (object) $value,
                '(unset)' => null,
                default => Unknown::value(),
            };
        } catch (\Throwable) {
            return Unknown::value();
        }
    }
}
