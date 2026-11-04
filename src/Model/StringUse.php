<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 属性被当作字符串使用的上下文（会隐式触发 __toString）。
 */
final class StringUse
{
    public const ECHO = 'echo';
    public const CONCAT = 'concat';
    public const CAST = 'cast';
    public const INCLUDE = 'include';
    public const STRING_FUNC = 'string_func';

    public function __construct(
        public string $property,
        public string $context,
        public int $line = 0,
        public ?string $raw = null,
    ) {
    }
}
