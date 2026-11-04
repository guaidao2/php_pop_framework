<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 链上一步：from 方法通过某个可控属性/字符串上下文，把控制流交给 to 方法。
 */
final class Edge
{
    /** @param array<int, ArgInfo> $argMapping 目标方法参数位置 => 上游实参 */
    public function __construct(
        public ClassInfo $fromClass,
        public MethodInfo $fromMethod,
        public ClassInfo $toClass,
        public MethodInfo $toMethod,
        public string $viaKind,
        public string $viaProperty,
        public array $argMapping = [],
        public int $line = 0,
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            '%s::%s --[%s $this->%s]--> %s::%s',
            $this->fromClass->name,
            $this->fromMethod->name,
            $this->viaKind,
            $this->viaProperty,
            $this->toClass->name,
            $this->toMethod->name,
        );
    }
}
