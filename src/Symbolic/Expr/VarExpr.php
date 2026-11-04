<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 符号变量。
 *
 * name 使用源代码中可读的形式：
 *   - $_GET['id']      输入源
 *   - $user            普通变量
 *   - $_SERVER['HTTP_HOST']
 */
final class VarExpr extends Expr
{
    public function __construct(
        public readonly string $name,
        private readonly Type $type = Type::Mixed,
    ) {
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function evaluate(array $env = []): mixed
    {
        return array_key_exists($this->name, $env) ? $env[$this->name] : Unknown::value();
    }

    public function toString(): string
    {
        return $this->name;
    }

    public function canonical(): string
    {
        return 'v:' . $this->name;
    }

    public function children(): array
    {
        return [];
    }
}
