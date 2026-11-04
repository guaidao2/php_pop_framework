<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

/**
 * 一个危险汇聚点（sink）的定义。
 */
final class Sink
{
    /**
     * @param list<int> $argIndexes 需要检查的参数下标；空数组表示全部参数
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly array $argIndexes = [],
        public readonly ?string $payload = null,
    ) {
    }
}
