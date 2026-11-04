<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\ConstExpr;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Security\SourceCatalog;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\TaintSource;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 抽象数组。
 *
 * items 保存已知键；open=true 表示还可能有未知键。
 * sourcePath 用于超全局数组（$_GET/$_POST/...）：
 * 读取未知键时会生成带污点的输入值，而不是普通未知值。
 */
final class ArrayValue extends Value
{
    /**
     * @param array<int|string, Value> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly bool $open = false,
        private readonly ?string $sourcePath = null,
        private readonly ?string $sourceKind = null,
        ?Taint $taint = null,
        private readonly ?Value $unknownElement = null,
    ) {
        parent::__construct($taint);
    }

    public function type(): Type
    {
        return Type::Array;
    }

    /**
     * @return array<int|string, Value>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function hasKey(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * 读取数组元素。
     *
     * - 已知键：返回保存的值
     * - 超全局数组的未知键：生成输入值（带污点）
     * - 其他未知键：返回 UnknownValue 或 null
     */
    public function get(int|string $key): Value
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        if ($this->sourcePath !== null) {
            return self::inputValue($this->sourcePath, $this->sourceKind, $key);
        }

        if ($this->unknownElement !== null) {
            return $this->unknownElement;
        }

        return $this->open
            ? new UnknownValue(ValueFactory::freshName('array-item'))
            : new ScalarValue(new ConstExpr(null));
    }

    /**
     * 抽象数组元素的代表值（用于 foreach / implode 等）。
     */
    public function elementValue(): Value
    {
        if ($this->items === [] && $this->sourcePath !== null) {
            $expression = $this->sourcePath . '[...]';
            $kind = $this->sourceKind ?? SourceCatalog::kindFromExpression($this->sourcePath);

            return new ScalarValue(
                new VarExpr($expression, Type::Mixed),
                Taint::of(new TaintSource($kind, $expression)),
            );
        }

        $values = array_values($this->items);
        if ($this->unknownElement !== null) {
            $values[] = $this->unknownElement;
        }
        if ($values === []) {
            return new ScalarValue(new ConstExpr(null));
        }

        return ValueFactory::merge($values);
    }

    /**
     * 读取动态键：结果可能是任意元素。
     */
    public function getDynamic(Expr $key): Value
    {
        if ($key->isConstant()) {
            $value = $key->constantValue();
            if (is_int($value) || is_string($value)) {
                return $this->get($value);
            }
        }

        $values = array_values($this->items);
        if ($this->unknownElement !== null) {
            $values[] = $this->unknownElement;
        }

        if ($values === []) {
            if ($this->sourcePath !== null) {
                return self::inputValue($this->sourcePath, $this->sourceKind, null, $key);
            }

            return new UnknownValue(ValueFactory::freshName('array-item'));
        }

        $merged = array_shift($values);
        foreach ($values as $value) {
            $merged = $merged->merge($value);
        }

        return $merged;
    }

    public function withKey(int|string $key, Value $value): self
    {
        $items = $this->items;
        $items[$key] = $value;

        return new self($items, $this->open, $this->sourcePath, $this->sourceKind, $this->taint, $this->unknownElement);
    }

    public function withKeyRemoved(int|string $key): self
    {
        $items = $this->items;
        unset($items[$key]);

        return new self($items, $this->open, $this->sourcePath, $this->sourceKind, $this->taint, $this->unknownElement);
    }

    public function append(Value $value): self
    {
        return $this->withKey(self::nextIndex(), $value);
    }

    private function nextIndex(): int
    {
        $max = -1;
        foreach (array_keys($this->items) as $key) {
            if (is_int($key) && $key > $max) {
                $max = $key;
            }
        }

        return $max + 1;
    }

    public function describe(): string
    {
        $parts = [];
        $count = 0;
        foreach ($this->items as $key => $value) {
            if ($count >= 6) {
                $parts[] = '...';
                break;
            }
            $parts[] = (is_int($key) ? $key : "'" . $key . "'") . ' => ' . $value->describe();
            $count++;
        }
        if ($this->open && $count < 6) {
            $parts[] = '...';
        }

        return '[' . implode(', ', $parts) . ']';
    }

    public function toExpr(): Expr
    {
        $constant = true;
        $values = [];
        foreach ($this->items as $key => $value) {
            $expr = $value->toExpr();
            if (!$expr->isConstant()) {
                $constant = false;
                break;
            }
            $values[$key] = $expr->constantValue();
        }

        if ($constant) {
            return new ConstExpr($values);
        }

        return new VarExpr($this->describe(), Type::Array);
    }

    public function merge(Value $other): Value
    {
        if (!$other instanceof self) {
            return new UnknownValue(ValueFactory::freshName('merged-array'), $this->taint->union($other->taint));
        }

        $keys = array_keys($this->items + $other->items);
        $items = [];
        foreach ($keys as $key) {
            $left = $this->items[$key] ?? null;
            $right = $other->items[$key] ?? null;
            if ($left !== null && $right !== null) {
                $items[$key] = $left->merge($right);
            } elseif ($left !== null) {
                $items[$key] = $left;
            } elseif ($right !== null) {
                $items[$key] = $right;
            }
        }

        return new self(
            $items,
            $this->open || $other->open,
            $this->sourcePath,
            $this->sourceKind,
            $this->taint->union($other->taint),
            $this->unknownElement !== null && $other->unknownElement !== null
                ? $this->unknownElement->merge($other->unknownElement)
                : ($this->unknownElement ?? $other->unknownElement),
        );
    }

    private static function inputValue(
        string $path,
        ?string $kind,
        int|string|null $key,
        ?Expr $dynamicKey = null,
    ): Value {
        $expr = $path . '[' . ($dynamicKey !== null
            ? $dynamicKey->toString()
            : (is_string($key) ? "'" . str_replace("'", "\\'", $key) . "'" : (string) $key)) . ']';

        $kind = $kind ?? SourceCatalog::kindFromExpression($path);
        $source = new TaintSource($kind, $expr);

        return new ScalarValue(
            new VarExpr($expr, Type::Mixed),
            Taint::of($source),
        );
    }
}
