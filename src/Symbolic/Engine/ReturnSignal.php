<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Value\Value;

final class ReturnSignal extends Signal
{
    public function __construct(public readonly Value $value)
    {
    }

    public function describe(): string
    {
        return 'return ' . $this->value->describe();
    }
}
