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
