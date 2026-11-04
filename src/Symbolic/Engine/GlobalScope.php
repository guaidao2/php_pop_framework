<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Value\Value;

/**
 * 全局作用域（跨函数共享）。
 *
 * 使用对象而不是数组，使得函数内联时可以把 global 变量的写回
 * 传播到调用者，而不需要复杂的引用建模。
 */
final class GlobalScope
{
    /** @var array<string, Value> */
    public array $vars = [];
}
