<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Model\Chain;

/**
 * 一次检测结果。detector 只负责"发现了什么"，渲染和 payload 生成交给 CLI。
 */
final class Finding
{
    /** @param list<string> $notes */
    public function __construct(
        public string $type,
        public string $title,
        public ?Chain $chain = null,
        public array $notes = [],
        public bool $feasible = true,
        public string $severity = 'high',
    ) {
    }

    /** @return list<string> */
    public function nodes(): array
    {
        return $this->chain?->nodes() ?? [];
    }
}
