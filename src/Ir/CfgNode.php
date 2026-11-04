<?php
declare(strict_types=1);

namespace PopChain\Ir;

/**
 * 控制流图里的一个节点。
 *
 * kind 取值：entry / exit / statement / condition / loop / switch / try / return / throw / jump
 * successors 里每项形如 ['to' => id, 'label' => 'true'|'false'|'exception'|null]
 */
final class CfgNode
{
    /** @param list<array{to:int,label:?string}> $successors */
    public function __construct(
        public int $id,
        public string $kind,
        public int $line = 0,
        public string $code = '',
        public array $successors = [],
        public array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'line' => $this->line,
            'code' => $this->code,
            'successors' => $this->successors,
            'meta' => $this->meta,
        ];
    }
}
