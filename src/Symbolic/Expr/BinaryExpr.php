<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 二元运算表达式。
 */
final class BinaryExpr extends Expr
{
    private Type $type;

    public function __construct(
        public readonly string $op,
        public readonly Expr $left,
        public readonly Expr $right,
    ) {
        $this->type = self::resultType($op, $left->type(), $right->type());
    }

    private static function resultType(string $op, Type $left, Type $right): Type
    {
        if (in_array($op, ['==', '===', '!=', '!==', '<', '<=', '>', '>=', '&&', '||', 'and', 'or', 'xor', '<=>'], true)) {
            return $op === '<=>' ? Type::Int : Type::Bool;
        }
        if ($op === '.') {
            return Type::String;
        }
        if ($op === '??') {
            return $left === Type::Null ? $right : ($left === Type::Mixed ? Type::Mixed : $left);
        }
        if ($op === '%' || in_array($op, ['&', '|', '^', '<<', '>>'], true)) {
            return Type::Int;
        }
        if ($op === '/' && $left === Type::Float) {
            return Type::Float;
        }
        if ($op === '**' && $left === Type::Int && $right === Type::Int) {
            return Type::Int;
        }
        if ($left === Type::Float || $right === Type::Float) {
            return Type::Float;
        }
        if ($left === Type::Int && $right === Type::Int) {
            return Type::Int;
        }

        return Type::Mixed;
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function toString(): string
    {
        return '(' . $this->left->toString() . ' ' . $this->op . ' ' . $this->right->toString() . ')';
    }

    public function canonical(): string
    {
        return 'b:' . $this->op . '(' . $this->left->canonical() . ',' . $this->right->canonical() . ')';
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }

    public function isPure(): bool
    {
        return $this->left->isPure() && $this->right->isPure();
    }

    public function evaluate(array $env = []): mixed
    {
        $left = $this->left->evaluate($env);

        // 逻辑运算短路：即使右值未知也可能确定结果。
        if (in_array($this->op, ['&&', 'and'], true)) {
            if ($left !== Unknown::value()) {
                if (!$left) {
                    return false;
                }
                $right = $this->right->evaluate($env);

                return $right === Unknown::value() ? Unknown::value() : (bool) $right;
            }
        }
        if (in_array($this->op, ['||', 'or'], true)) {
            if ($left !== Unknown::value()) {
                if ($left) {
                    return true;
                }
                $right = $this->right->evaluate($env);

                return $right === Unknown::value() ? Unknown::value() : (bool) $right;
            }
        }

        $right = $this->right->evaluate($env);

        if ($left === Unknown::value() || $right === Unknown::value()) {
            return Unknown::value();
        }

        try {
            return match ($this->op) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $left / $right,
                '%' => $left % $right,
                '**' => $left ** $right,
                '.' => self::stringify($left) . self::stringify($right),
                '==' => $left == $right,
                '===' => $left === $right,
                '!=' => $left != $right,
                '!==' => $left !== $right,
                '<' => $left < $right,
                '<=' => $left <= $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<=>' => $left <=> $right,
                '&&', 'and' => (bool) $left && (bool) $right,
                '||', 'or' => (bool) $left || (bool) $right,
                'xor' => (bool) $left xor (bool) $right,
                '&' => $left & $right,
                '|' => $left | $right,
                '^' => $left ^ $right,
                '<<' => $left << $right,
                '>>' => $left >> $right,
                '??' => $left ?? $right,
                default => Unknown::value(),
            };
        } catch (\Throwable) {
            return Unknown::value();
        }
    }

    public static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return 'Array';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if ($value === null) {
            return '';
        }
        if (is_object($value) && !method_exists($value, '__toString')) {
            return 'Object';
        }

        return (string) $value;
    }
}
