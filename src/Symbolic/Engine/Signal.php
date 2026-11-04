<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

/**
 * 控制流信号：return / break / continue / exit / throw。
 *
 * 信号保存在 State 上，执行器遇到带信号的状态时停止当前语句序列。
 */
abstract class Signal
{
    abstract public function describe(): string;
}
