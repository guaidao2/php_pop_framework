<?php
declare(strict_types=1);

namespace PopChain\Ir;

/**
 * 一个方法的控制流图。
 *
 * 这是给分析后端（例如符号执行引擎）用的语句级 IR；
 * 现有的 ChainFinder 仍然只依赖 MethodInfo 的摘要信息，两边互不影响。
 */
final class MethodCfg
{
    /** @param list<CfgNode> $nodes @param list<int> $exits */
    public function __construct(
        public string $class,
        public string $method,
        public array $nodes = [],
        public int $entry = 0,
        public array $exits = [],
    ) {
    }

    /** @return list<CfgNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function node(int $id): ?CfgNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }
        return null;
    }

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'entry' => $this->entry,
            'exits' => $this->exits,
            'nodes' => array_map(static fn (CfgNode $node): array => $node->toArray(), $this->nodes),
        ];
    }
}
