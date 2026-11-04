<?php
declare(strict_types=1);

namespace PopChain\Model;

final class ClassInfo
{
    /** @param list<string> $interfaces @param list<string> $traits @param list<PropertyInfo> $properties @param list<MethodInfo> $methods */
    public function __construct(
        public string $name,
        public ?string $parent = null,
        public array $interfaces = [],
        public array $traits = [],
        public array $properties = [],
        public array $methods = [],
        public string $file = '',
        public int $line = 0,
        public bool $abstract = false,
        public bool $anonymous = false,
        public bool $trait = false,
        public bool $interface = false,
        public bool $enum = false,
        public array $traitAliases = [],
        public array $traitPrecedence = [],
    ) {
    }

    /** 能否在 payload 里被实例化（abstract / interface / trait / enum / 匿名类都不行）。 */
    public function isInstantiable(): bool
    {
        return !$this->abstract && !$this->anonymous && !$this->trait
            && !$this->interface && !$this->enum;
    }

    public function method(string $name): ?MethodInfo
    {
        foreach ($this->methods as $method) {
            if (strcasecmp($method->name, $name) === 0) {
                return $method;
            }
        }
        return null;
    }

    public function property(string $name): ?PropertyInfo
    {
        foreach ($this->properties as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }
        return null;
    }

    public function hasMethod(string $name): bool
    {
        return $this->method($name) !== null;
    }

    public function magicMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn (MethodInfo $m): bool => $m->magic,
        ));
    }

    public function shortName(): string
    {
        $pos = strrpos($this->name, '\\');
        return $pos === false ? $this->name : substr($this->name, $pos + 1);
    }
}
