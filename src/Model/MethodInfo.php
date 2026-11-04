<?php
declare(strict_types=1);

namespace PopChain\Model;

final class MethodInfo
{
    /** @param list<ParamInfo> $params @param list<CallSite> $calls @param list<StringUse> $stringUses */
    public function __construct(
        public string $name,
        public string $visibility = 'public',
        public bool $static = false,
        public array $params = [],
        public array $calls = [],
        public int $line = 0,
        public bool $magic = false,
        public string $declaringClass = '',
        public array $stringUses = [],
    ) {
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function paramAt(int $position): ?ParamInfo
    {
        return $this->params[$position] ?? null;
    }

    public function paramNamed(string $name): ?ParamInfo
    {
        foreach ($this->params as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }
        return null;
    }
}
