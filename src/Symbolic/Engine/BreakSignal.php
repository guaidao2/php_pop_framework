<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

final class BreakSignal extends Signal
{
    public function __construct(public readonly int $levels = 1)
    {
    }

    public function describe(): string
    {
        return 'break ' . $this->levels;
    }
}
