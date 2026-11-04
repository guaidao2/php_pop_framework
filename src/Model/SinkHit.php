<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 一次 sink 命中：哪个危险函数的哪个参数来自可控数据。
 */
final class SinkHit
{
    public function __construct(
        public string $function,
        public string $category,
        public int $position,
        public ArgInfo $arg,
        public int $line = 0,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s() arg #%d ← %s', $this->function, $this->position, $this->arg->describe());
    }
}
