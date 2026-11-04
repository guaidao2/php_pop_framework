<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Symbolic;

/**
 * 表示"未知值"的单例哨兵。
 *
 * 与 null 区分：null 是 PHP 的合法常量，而 Unknown 表示符号执行无法确定的值。
 */
final class Unknown
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function value(): self
    {
        return self::$instance ??= new self();
    }

    public function __toString(): string
    {
        return '<unknown>';
    }
}
