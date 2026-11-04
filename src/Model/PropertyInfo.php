<?php
declare(strict_types=1);

namespace PopChain\Model;

final class PropertyInfo
{
    public function __construct(
        public string $name,
        public string $visibility = 'public',
        public bool $static = false,
        public ?string $defaultRaw = null,
        public bool $promoted = false,
        public string $declaringClass = '',
    ) {
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}
