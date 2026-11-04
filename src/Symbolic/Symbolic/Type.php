<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Symbolic;

/**
 * 符号值的类型格。
 *
 * Mixed 表示"可能是任意类型"，用于未知函数返回值等场景；
 * Array/Object 在 Value 层有独立的容器实现，但表达式层仍然用这个枚举描述类型。
 */
enum Type: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';
    case Array = 'array';
    case Object = 'object';
    case Null = 'null';
    case Mixed = 'mixed';

    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Float;
    }

    public function isScalar(): bool
    {
        return $this === self::Int
            || $this === self::Float
            || $this === self::String
            || $this === self::Bool;
    }

    public function isStringable(): bool
    {
        return $this->isScalar() || $this === self::Null || $this === self::Mixed;
    }

    public function isString(): bool
    {
        return $this === self::String;
    }

    public function isInt(): bool
    {
        return $this === self::Int;
    }

    /**
     * 把 PHP 运行时值映射为符号类型。
     */
    public static function ofValue(mixed $value): self
    {
        return match (true) {
            is_int($value) => self::Int,
            is_float($value) => self::Float,
            is_string($value) => self::String,
            is_bool($value) => self::Bool,
            is_array($value) => self::Array,
            is_object($value) => self::Object,
            $value === null => self::Null,
            default => self::Mixed,
        };
    }

    public function phpName(): string
    {
        return $this->value;
    }
}
