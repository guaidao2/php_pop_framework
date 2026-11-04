<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Expr\Simplifier;
use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 符号表达式基类。
 *
 * 表达式树是分析过程中的不可变值：
 *   - ConstExpr  常量
 *   - VarExpr    符号变量（用户输入、未知值）
 *   - BinaryExpr 二元运算
 *   - UnaryExpr  一元运算 / 类型转换
 *   - CallExpr   函数调用结果
 *   - ArrayAccessExpr 数组下标访问
 *   - TernaryExpr 三元表达式
 *
 * 约定：所有构造函数都应通过 Expr::bin() / Expr::unary() / Expr::call()
 * 等工厂方法创建，以便统一执行常量折叠。
 */
abstract class Expr
{
    abstract public function type(): Type;

    /** 面向用户的可读形式，例如 strlen($_GET['a']) > 3 */
    abstract public function toString(): string;

    /** 用于等价判断的规范形式 */
    abstract public function canonical(): string;

    /**
     * @return list<Expr>
     */
    abstract public function children(): array;

    /**
     * 在给定赋值下求值；无法确定时返回 Unknown::value()。
     *
     * @param array<string, mixed> $env
     */
    public function evaluate(array $env = []): mixed
    {
        return Unknown::value();
    }

    public function isConstant(): bool
    {
        return false;
    }

    public function constantValue(): mixed
    {
        return Unknown::value();
    }

    /**
     * 表达式是否没有副作用。常量折叠在删除子表达式前用它保证安全。
     */
    public function isPure(): bool
    {
        return true;
    }

    public function contains(string $variable): bool
    {
        if ($this instanceof VarExpr && $this->name === $variable) {
            return true;
        }
        foreach ($this->children() as $child) {
            if ($child->contains($variable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        $names = [];
        if ($this instanceof VarExpr) {
            $names[$this->name] = true;
        }
        foreach ($this->children() as $child) {
            foreach ($child->variables() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    public function equals(?Expr $other): bool
    {
        return $other !== null && $this->canonical() === $other->canonical();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    // ------------------------------------------------------------------
    // 工厂方法与便捷构造
    // ------------------------------------------------------------------

    public static function literal(mixed $value): ConstExpr
    {
        return new ConstExpr($value);
    }

    public static function variable(string $name, Type $type = Type::Mixed): VarExpr
    {
        return new VarExpr($name, $type);
    }

    public static function bin(string $op, Expr $left, Expr $right): Expr
    {
        return Simplifier::binary($op, $left, $right);
    }

    public static function unary(string $op, Expr $operand): Expr
    {
        return Simplifier::unary($op, $operand);
    }

    /**
     * @param list<Expr> $args
     */
    public static function call(string $name, array $args, Type $type = Type::Mixed): Expr
    {
        return Simplifier::call($name, $args, $type);
    }

    public static function arrayAccess(Expr $array, ?Expr $key): Expr
    {
        return new ArrayAccessExpr($array, $key);
    }

    public static function ternary(Expr $cond, Expr $ifTrue, Expr $ifFalse): Expr
    {
        return Simplifier::ternary($cond, $ifTrue, $ifFalse);
    }

    /**
     * 把 PHP 常量转换为表达式：数组会被递归转换，但只在完全可表示时。
     */
    public static function fromPhp(mixed $value): Expr
    {
        return new ConstExpr($value);
    }
}
