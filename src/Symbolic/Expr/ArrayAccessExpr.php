<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 数组下标访问，例如 $a['k']、$_GET[$name]。
 */
final class ArrayAccessExpr extends Expr
{
    public function __construct(
        public readonly Expr $array,
        public readonly ?Expr $key,
    ) {
    }

    public function type(): Type
    {
        return Type::Mixed;
    }

    public function toString(): string
    {
        return $this->array->toString() . '[' . ($this->key === null ? '' : $this->key->toString()) . ']';
    }

    public function canonical(): string
    {
        return 'a:' . $this->array->canonical() . '[' . ($this->key?->canonical() ?? '@append') . ']';
    }

    public function children(): array
    {
        return $this->key === null ? [$this->array] : [$this->array, $this->key];
    }

    public function isPure(): bool
    {
        return $this->array->isPure() && ($this->key === null || $this->key->isPure());
    }

    public function evaluate(array $env = []): mixed
    {
        $array = $this->array->evaluate($env);
        if ($array === Unknown::value() || !is_array($array)) {
            return Unknown::value();
        }
        if ($this->key === null) {
            return Unknown::value();
        }
        $key = $this->key->evaluate($env);
        if ($key === Unknown::value() || !is_int($key) && !is_string($key)) {
            return Unknown::value();
        }

        return array_key_exists($key, $array) ? $array[$key] : Unknown::value();
    }
}
