<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Value\Value;

final class ExitSignal extends Signal
{
    public function __construct(public readonly ?Value $value = null)
    {
    }

    public function describe(): string
    {
        return 'exit';
    }
}
