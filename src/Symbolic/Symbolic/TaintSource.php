<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Symbolic;

/**
 * 一个污点来源，例如 $_GET['name']、$_SERVER['HTTP_HOST'] 或 php://input。
 *
 * kind 用于按类别做净化（例如 intval() 清除所有类别的污点，
 * htmlspecialchars() 只清除 XSS 类别）。
 */
final class TaintSource
{
    public function __construct(
        public readonly string $kind,
        public readonly string $expression,
    ) {
    }

    public function id(): string
    {
        return $this->kind . '|' . $this->expression;
    }

    public function describe(): string
    {
        return $this->expression;
    }
}
