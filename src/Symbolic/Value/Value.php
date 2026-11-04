<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 运行时抽象值基类。
 *
 * Value 是"符号执行状态里变量持有的东西"：
 *   - ScalarValue  标量，内部是一个符号表达式
 *   - ArrayValue   抽象数组，逐键保存 Value
 *   - ObjectValue  抽象对象，保存类名与属性
 *   - ClosureValue 闭包
 *   - UnknownValue 完全未知的值
 *
 * 所有 Value 都应视为不可变：赋值时创建新对象，方便状态 fork。
 */
abstract class Value
{
    public Taint $taint;

    public function __construct(?Taint $taint = null)
    {
        $this->taint = $taint ?? Taint::none();
    }

    abstract public function type(): Type;

    /** 面向报告/调试的可读形式 */
    abstract public function describe(): string;

    /** 转换为表达式（用于运算与约束） */
    abstract public function toExpr(): Expr;

    /** 路径合并：同一个变量在不同路径上的值 */
    abstract public function merge(Value $other): Value;

    public function withTaint(Taint $taint): static
    {
        $clone = clone $this;
        $clone->taint = $taint;

        return $clone;
    }

    public function isTainted(): bool
    {
        return $this->taint->isTainted();
    }
}
