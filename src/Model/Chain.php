<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 一条完整的反序列化利用链。
 */
final class Chain
{
    /** @param list<Edge> $edges */
    public function __construct(
        public ClassInfo $entryClass,
        public MethodInfo $entryMethod,
        public array $edges = [],
        public ?SinkHit $sink = null,
        public ?ClassInfo $sinkClass = null,
        public ?MethodInfo $sinkMethod = null,
    ) {
    }

    /** @return list<string> */
    public function nodes(): array
    {
        $nodes = [$this->entryClass->name . '::' . $this->entryMethod->name];
        foreach ($this->edges as $edge) {
            $nodes[] = $edge->toClass->name . '::' . $edge->toMethod->name;
        }
        return $nodes;
    }

    public function signature(): string
    {
        return implode('>', $this->nodes()) . '|' . ($this->sink?->function ?? '');
    }

    public function length(): int
    {
        return count($this->nodes());
    }

    /** 最后一条边携带了多少个实参映射（用于在重复 signature 里挑信息更全的那条）。 */
    public function mappedArgCount(): int
    {
        if ($this->edges === []) {
            return 0;
        }
        return count($this->edges[count($this->edges) - 1]->argMapping);
    }

    public function sinkObjectClass(): ?ClassInfo
    {
        if ($this->sinkClass !== null) {
            return $this->sinkClass;
        }
        if ($this->edges === []) {
            return $this->entryClass;
        }
        return $this->edges[count($this->edges) - 1]->toClass;
    }

    public function sinkObjectMethod(): ?MethodInfo
    {
        if ($this->sinkMethod !== null) {
            return $this->sinkMethod;
        }
        if ($this->edges === []) {
            return $this->entryMethod;
        }
        return $this->edges[count($this->edges) - 1]->toMethod;
    }
}
