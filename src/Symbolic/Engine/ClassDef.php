<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

/**
 * 已索引的类/接口/trait 定义。
 */
final class ClassDef
{
    /**
     * @param array<string, FunctionDef> $methods 小写方法名 => 定义
     * @param list<string> $traits
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $parent,
        public readonly array $methods,
        public readonly string $file,
        public readonly int $line,
        public readonly array $traits = [],
    ) {
    }

    public function method(string $name): ?FunctionDef
    {
        return $this->methods[strtolower($name)] ?? null;
    }

    public function shortName(): string
    {
        $position = strrpos($this->name, '\\');

        return $position === false ? $this->name : substr($this->name, $position + 1);
    }
}
