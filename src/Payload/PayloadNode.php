<?php
declare(strict_types=1);

namespace PopChain\Payload;

/**
 * payload 中的一个对象节点。
 */
final class PayloadNode
{
    /** @var array<string, array{value:mixed, visibility:string, declaringClass:string}> */
    public array $properties = [];

    /** 额外声明几个属性但不写内容：让 unserialize 在该对象处解析失败，从而跳过 __wakeup */
    public int $declaredBump = 0;

    public function __construct(public string $class)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function set(string $name, mixed $value, string $visibility, string $declaringClass): void
    {
        $this->properties[$name] = [
            'value' => $value,
            'visibility' => $visibility,
            'declaringClass' => $declaringClass,
        ];
    }
}
