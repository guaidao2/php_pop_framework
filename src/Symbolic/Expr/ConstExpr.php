<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 常量表达式。value 可以是标量、null 或数组。
 */
final class ConstExpr extends Expr
{
    private Type $type;

    public function __construct(private readonly mixed $value)
    {
        $this->type = Type::ofValue($value);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function isConstant(): bool
    {
        return true;
    }

    public function constantValue(): mixed
    {
        return $this->value;
    }

    public function evaluate(array $env = []): mixed
    {
        return $this->value;
    }

    public function toString(): string
    {
        return self::export($this->value);
    }

    public function canonical(): string
    {
        return 'c:' . self::export($this->value);
    }

    public function children(): array
    {
        return [];
    }

    public static function export(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NAN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'INF' : '-INF';
            }
            $formatted = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

            return $formatted === '' ? '0.0' : $formatted;
        }
        if (is_array($value)) {
            $parts = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $exported = self::export($item);
                $parts[] = $isList ? $exported : self::export($key) . ' => ' . $exported;
            }

            return '[' . implode(', ', $parts) . ']';
        }
        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }

        return '<' . get_debug_type($value) . '>';
    }
}
