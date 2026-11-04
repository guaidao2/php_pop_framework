<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/**
 * 已索引的函数/方法定义。
 */
final class FunctionDef
{
    /**
     * @param list<Param> $params
     * @param list<Stmt> $stmts
     */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly array $stmts,
        public readonly string $file,
        public readonly int $line,
        public readonly ?string $namespace = null,
        public readonly ?ClassDef $class = null,
    ) {
    }

    public function shortName(): string
    {
        $position = strrpos($this->name, '\\');

        return $position === false ? $this->name : substr($this->name, $position + 1);
    }

    public function describe(): string
    {
        return ($this->class !== null ? $this->class->name . '::' : '') . $this->shortName() . '()';
    }
}
