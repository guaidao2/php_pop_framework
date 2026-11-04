<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 方法体内一个可被链利用的调用点。
 */
final class CallSite
{
    public const FUNCTION_CALL = 'function';       // foo(...)
    public const METHOD_CALL = 'method';           // $obj->m(...)
    public const CALL_PROPERTY = 'call_property';  // ($this->prop)(...)
    public const STATIC_CALL = 'static';           // Foo::m(...)
    public const NEW_OBJECT = 'new';               // new Foo(...)
    public const PROPERTY_GET = 'property_get';    // $this->prop->name 读取 → __get
    public const CALLABLE_ARRAY_INVOKE = 'callable_array_invoke'; // [$this->obj, 'm'](...)
    public const PROPERTY_ISSET = 'property_isset'; // isset($this->prop->name) → __isset

    /** @param list<ArgInfo> $args */
    public function __construct(
        public string $kind,
        public ?string $target = null,
        public array $args = [],
        public int $line = 0,
        public ?string $receiverKind = null,
        public ?string $receiverName = null,
        public ?string $raw = null,
    ) {
    }

    /** 调用的接收者是否来自可控属性。 */
    public function receiverIsProperty(): bool
    {
        return $this->receiverKind === ArgInfo::THIS_PROPERTY;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::FUNCTION_CALL => $this->target . '(...)',
            self::METHOD_CALL => '$this->' . ($this->receiverName ?? '?') . '->' . ($this->target ?? '?') . '(...)',
            self::CALL_PROPERTY => '($this->' . ($this->receiverName ?? '?') . ')(...)',
            self::STATIC_CALL => ($this->target ?? '?') . '::(...)',
            self::NEW_OBJECT => 'new ' . ($this->target ?? '?') . '(...)',
            self::PROPERTY_GET => '$this->' . ($this->receiverName ?? '?') . '->' . ($this->target ?? '?'),
            self::CALLABLE_ARRAY_INVOKE => '[$this->' . ($this->receiverName ?? '?') . ", '" . ($this->target ?? '?') . "'](...)",
            self::PROPERTY_ISSET => 'isset($this->' . ($this->receiverName ?? '?') . '->' . ($this->target ?? '?') . ')',
            default => $this->raw ?? 'call',
        };
    }
}
