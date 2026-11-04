<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 表达式工厂 + 常量折叠 + 代数化简。
 *
 * 化简有两个目的：
 *   1. 让路径条件更容易被内置求解器判定；
 *   2. 在报告中展示更贴近源码的约束。
 *
 * 所有规则都必须保持 PHP 语义（含短路语义），否则会产生错误的路径剪枝。
 */
final class Simplifier
{
    public static function simplify(Expr $expr): Expr
    {
        if ($expr instanceof BinaryExpr) {
            return self::binary(
                $expr->op,
                self::simplify($expr->left),
                self::simplify($expr->right),
            );
        }
        if ($expr instanceof UnaryExpr) {
            return self::unary($expr->op, self::simplify($expr->operand));
        }
        if ($expr instanceof CallExpr) {
            return self::call(
                $expr->name,
                array_map(self::simplify(...), $expr->args),
                $expr->type(),
            );
        }
        if ($expr instanceof ArrayAccessExpr) {
            return self::arrayAccess(
                self::simplify($expr->array),
                $expr->key === null ? null : self::simplify($expr->key),
            );
        }
        if ($expr instanceof TernaryExpr) {
            return self::ternary(
                self::simplify($expr->cond),
                self::simplify($expr->ifTrue),
                self::simplify($expr->ifFalse),
            );
        }

        return $expr;
    }

    public static function binary(string $op, Expr $left, Expr $right): Expr
    {
        // 1. 常量折叠
        if ($left->isConstant() && $right->isConstant()) {
            $value = (new BinaryExpr($op, $left, $right))->evaluate([]);
            if ($value !== Unknown::value()) {
                return new ConstExpr($value);
            }
        }

        // 2. ?? 语义
        if ($op === '??') {
            if ($left->isConstant()) {
                $value = $left->constantValue();

                return $value === null ? $right : $left;
            }
            if ($left->equals($right)) {
                return $left;
            }
        }

        // 3. 字符串拼接
        if ($op === '.') {
            if ($left->isConstant() && $left->constantValue() === '') {
                return self::castTo(Type::String, $right);
            }
            if ($right->isConstant() && $right->constantValue() === '') {
                return self::castTo(Type::String, $left);
            }
        }

        // 4. 数值代数
        $numeric = static fn (Expr $e): bool => in_array($e->type(), [Type::Int, Type::Float, Type::Mixed], true);
        if ($numeric($left) && $numeric($right)) {
            if (($op === '+' || $op === '-') && $right->isConstant() && $right->constantValue() === 0) {
                return $left;
            }
            if ($op === '+' && $left->isConstant() && $left->constantValue() === 0) {
                return $right;
            }
            if ($op === '*' && $right->isConstant() && $right->constantValue() === 1) {
                return $left;
            }
            if ($op === '*' && $left->isConstant() && $left->constantValue() === 1) {
                return $right;
            }
            if ($op === '*' && $left->isConstant() && $left->constantValue() === 0 && $right->isPure()) {
                return $left;
            }
            if ($op === '*' && $right->isConstant() && $right->constantValue() === 0 && $left->isPure()) {
                return $right;
            }
            if (($op === '/' || $op === '**') && $right->isConstant() && $right->constantValue() === 1) {
                return $left;
            }
        }

        // 5. 逻辑代数（保持短路语义）
        if ($op === '&&' || $op === 'and') {
            if ($left->isConstant()) {
                return $left->constantValue()
                    ? self::castTo(Type::Bool, $right)
                    : new ConstExpr(false);
            }
            if ($right->isConstant()) {
                if ($right->constantValue()) {
                    return self::castTo(Type::Bool, $left);
                }
                // 右侧为 false：左侧若有副作用则必须保留
                return $left->isPure() ? new ConstExpr(false) : new BinaryExpr($op, $left, $right);
            }
        }
        if ($op === '||' || $op === 'or') {
            if ($left->isConstant()) {
                return $left->constantValue()
                    ? new ConstExpr(true)
                    : self::castTo(Type::Bool, $right);
            }
            if ($right->isConstant()) {
                if (!$right->constantValue()) {
                    return self::castTo(Type::Bool, $left);
                }
                return $left->isPure() ? new ConstExpr(true) : new BinaryExpr($op, $left, $right);
            }
        }

        // 6. 自比较
        if ($left->equals($right) && $left->type() !== Type::Float) {
            return match ($op) {
                '==', '===', '<=', '>=' => new ConstExpr(true),
                '!=', '!==', '<', '>' => new ConstExpr(false),
                default => new BinaryExpr($op, $left, $right),
            };
        }

        return new BinaryExpr($op, $left, $right);
    }

    public static function unary(string $op, Expr $operand): Expr
    {
        if ($operand->isConstant()) {
            $value = (new UnaryExpr($op, $operand))->evaluate([]);
            if ($value !== Unknown::value()) {
                return new ConstExpr($value);
            }
        }

        if ($op === '!') {
            if ($operand instanceof UnaryExpr && $operand->op === '!') {
                return self::castTo(Type::Bool, $operand->operand);
            }
            // !(a == b) => a != b，便于求解器处理
            if ($operand instanceof BinaryExpr) {
                $negated = match ($operand->op) {
                    '==' => '!=',
                    '!=' => '==',
                    '===' => '!==',
                    '!==' => '===',
                    '<' => '>=',
                    '>=' => '<',
                    '>' => '<=',
                    '<=' => '>',
                    default => null,
                };
                if ($negated !== null) {
                    return self::binary($negated, $operand->left, $operand->right);
                }
            }
        }

        if (($op === '-' || $op === '+') && $operand instanceof UnaryExpr && $operand->op === $op) {
            return $op === '+' ? $operand : $operand->operand;
        }

        // 幂等类型转换
        if (str_starts_with($op, '(') && $operand instanceof UnaryExpr && $operand->op === $op) {
            return $operand;
        }

        if ($op === '(bool)' && $operand->type() === Type::Bool) {
            return $operand;
        }

        return new UnaryExpr($op, $operand);
    }

    /**
     * @param list<Expr> $args
     */
    public static function call(string $name, array $args, Type $type = Type::Mixed): Expr
    {
        $allConstant = true;
        foreach ($args as $arg) {
            if (!$arg->isConstant()) {
                $allConstant = false;
                break;
            }
        }

        $call = new CallExpr($name, $args, $type);

        if ($allConstant) {
            $value = $call->evaluate([]);
            if ($value !== Unknown::value()) {
                return new ConstExpr($value);
            }
        }

        // strval()/trim() 等对字符串是恒等变换，但保留函数调用以表达语义，
        // 仅折叠 strval(string) 这种完全无损的情形。
        if (strtolower($name) === 'strval' && isset($args[0]) && $args[0]->type() === Type::String) {
            return $args[0];
        }

        return $call;
    }

    public static function arrayAccess(Expr $array, ?Expr $key): Expr
    {
        if ($key !== null && $array->isConstant() && $key->isConstant()) {
            $value = (new ArrayAccessExpr($array, $key))->evaluate([]);
            if ($value !== Unknown::value()) {
                return new ConstExpr($value);
            }
        }

        return new ArrayAccessExpr($array, $key);
    }

    public static function ternary(Expr $cond, Expr $ifTrue, Expr $ifFalse): Expr
    {
        if ($cond->isConstant()) {
            return $cond->constantValue() ? $ifTrue : $ifFalse;
        }
        if ($ifTrue->equals($ifFalse) && $cond->isPure()) {
            return $ifTrue;
        }

        return new TernaryExpr($cond, $ifTrue, $ifFalse);
    }

    public static function castTo(Type $type, Expr $expr): Expr
    {
        if ($expr->type() === $type) {
            return $expr;
        }
        $op = match ($type) {
            Type::Int => '(int)',
            Type::Float => '(float)',
            Type::String => '(string)',
            Type::Bool => '(bool)',
            Type::Array => '(array)',
            Type::Object => '(object)',
            default => null,
        };
        if ($op === null) {
            return $expr;
        }

        return self::unary($op, $expr);
    }
}
