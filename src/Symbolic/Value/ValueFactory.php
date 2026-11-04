<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\ConstExpr;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\TaintSource;
use PopChain\Symbolic\Symbolic\Type;

/**
 * Value 层的构造与合并工具。
 */
final class ValueFactory
{
    private static int $counter = 0;

    public static function freshName(string $prefix): string
    {
        self::$counter++;

        return '<?' . $prefix . ':' . self::$counter . '>';
    }

    public static function scalar(Expr $expr, ?Taint $taint = null): ScalarValue
    {
        return new ScalarValue($expr, $taint);
    }

    public static function unknown(?Taint $taint = null): UnknownValue
    {
        return new UnknownValue(self::freshName('unknown'), $taint);
    }

    public static function fromConstant(mixed $value, ?Taint $taint = null): Value
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = self::fromConstant($item, $taint);
            }

            return new ArrayValue($items, false, null, null, $taint);
        }
        if (is_object($value)) {
            return new ObjectValue($value::class, [], $taint);
        }

        return new ScalarValue(new ConstExpr($value), $taint);
    }

    /**
     * 生成一个来自输入源的值。
     */
    public static function fromInput(string $expression, string $kind): ScalarValue
    {
        return new ScalarValue(
            new VarExpr($expression, Type::Mixed),
            Taint::of(new TaintSource($kind, $expression)),
        );
    }

    public static function toExpr(Value $value): Expr
    {
        return $value->toExpr();
    }

    /**
     * 把多个路径上的值合并成一个抽象值。
     *
     * @param list<Value> $values
     */
    public static function merge(array $values): Value
    {
        if ($values === []) {
            return self::unknown();
        }

        $merged = array_shift($values);
        foreach ($values as $value) {
            $merged = $merged->merge($value);
        }

        return $merged;
    }

    /**
     * 判断值是否为"确定的常量"。
     */
    public static function concrete(Value $value): mixed
    {
        $expr = $value->toExpr();

        return $expr->isConstant() ? $expr->constantValue() : null;
    }

    public static function isConcrete(Value $value): bool
    {
        return $value->toExpr()->isConstant();
    }
}
