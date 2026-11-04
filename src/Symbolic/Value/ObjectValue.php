<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 抽象对象：保留类名与可追踪的属性。
 *
 * 不对真实对象做内存建模；属性按名称保存 Value，
 * 足以支撑 $obj->prop 形式的数据流。
 */
final class ObjectValue extends Value
{
    /**
     * @param array<string, Value> $properties
     */
    public function __construct(
        public readonly string $class,
        private readonly array $properties = [],
        ?Taint $taint = null,
    ) {
        parent::__construct($taint);
    }

    public function type(): Type
    {
        return Type::Object;
    }

    /**
     * @return array<string, Value>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    public function getProperty(string $name): Value
    {
        return $this->properties[$name] ?? new UnknownValue(ValueFactory::freshName('prop-' . $name));
    }

    public function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    public function withProperty(string $name, Value $value): self
    {
        $properties = $this->properties;
        $properties[$name] = $value;

        return new self($this->class, $properties, $this->taint);
    }

    public function describe(): string
    {
        $parts = [];
        foreach ($this->properties as $name => $value) {
            $parts[] = $name . '=' . $value->describe();
        }

        return 'object(' . $this->class . '){' . implode(', ', $parts) . '}';
    }

    public function toExpr(): Expr
    {
        return new VarExpr($this->describe(), Type::Object);
    }

    public function merge(Value $other): Value
    {
        if ($other instanceof self && $other->class === $this->class) {
            $names = array_keys($this->properties + $other->properties);
            $properties = [];
            foreach ($names as $name) {
                $left = $this->properties[$name] ?? null;
                $right = $other->properties[$name] ?? null;
                if ($left !== null && $right !== null) {
                    $properties[$name] = $left->merge($right);
                } elseif ($left !== null) {
                    $properties[$name] = $left;
                } elseif ($right !== null) {
                    $properties[$name] = $right;
                }
            }

            return new self($this->class, $properties, $this->taint->union($other->taint));
        }

        return new UnknownValue(ValueFactory::freshName('merged-object'), $this->taint->union($other->taint));
    }
}
