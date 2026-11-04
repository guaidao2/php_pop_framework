<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Solver;

/**
 * 约束可满足性检查结果。
 *
 * Unknown 表示求解器无法判定：为了不漏报漏洞，调用方应把
 * Unknown 视为"可能可满足"，继续探索路径。
 */
enum Status
{
    case Sat;
    case Unsat;
    case Unknown;

    public function label(): string
    {
        return match ($this) {
            self::Sat => 'SAT',
            self::Unsat => 'UNSAT',
            self::Unknown => 'UNKNOWN',
        };
    }
}
