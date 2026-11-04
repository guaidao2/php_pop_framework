<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

final class ContinueSignal extends Signal
{
    public function __construct(public readonly int $levels = 1)
    {
    }

    public function describe(): string
    {
        return 'continue ' . $this->levels;
    }
}
