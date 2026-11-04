<?php
declare(strict_types=1);

namespace PopChain\Model;

final class ParamInfo
{
    public function __construct(
        public string $name,
        public int $position,
        public ?string $defaultRaw = null,
        public ?string $type = null,
        public bool $promoted = false,
        public string $visibility = 'public',
    ) {
    }
}
