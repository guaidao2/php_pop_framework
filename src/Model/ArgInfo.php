<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 一个方法调用实参的污点来源描述。
 */
final class ArgInfo
{
    public const THIS_PROPERTY = 'this_property';
    public const VARIABLE = 'variable';
    public const STRING = 'string';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const NULL = 'null';
    public const ARRAY_LITERAL = 'array_literal';
    public const CONCAT = 'concat';
    public const CALLABLE_ARRAY = 'callable_array';
    public const ARRAY_ELEMENT = 'array_element';
    public const PARAM_ELEMENT = 'param_element';
    public const ARG_ELEMENT = 'arg_element';
    public const EXPR = 'expr';

    /**
     * @param list<array{0:string,1:string}> $parts CONCAT 片段：['lit', 文本] 或 ['prop', 属性名]
     */
    public function __construct(
        public string $kind,
        public ?string $name = null,
        public ?string $raw = null,
        public mixed $literal = null,
        public array $parts = [],
    ) {
    }

    /** 是否来自对象属性，也就是反序列化时可控。 */
    public function isControllable(): bool
    {
        return $this->kind === self::THIS_PROPERTY
            || $this->kind === self::CALLABLE_ARRAY
            || $this->kind === self::ARRAY_ELEMENT
            || ($this->kind === self::CONCAT && $this->propertyNames() !== []);
    }

    /** 是否是编译期可确定的常量值。 */
    public function isLiteral(): bool
    {
        return in_array($this->kind, [
            self::STRING, self::INT, self::FLOAT, self::BOOL, self::NULL, self::ARRAY_LITERAL,
        ], true);
    }

    /** CONCAT 片段里涉及的属性名（去重，保持出现顺序）。 */
    public function propertyNames(): array
    {
        $names = [];
        foreach ($this->parts as [$kind, $text]) {
            if ($kind === 'prop' && !in_array($text, $names, true)) {
                $names[] = $text;
            }
        }
        return $names;
    }

    /** PARAM_ELEMENT 的维度路径（支持 $data['a']['b']），展示用。 */
    public function describePath(): string
    {
        $path = is_array($this->literal) ? $this->literal : [$this->literal];
        $out = '';
        foreach ($path as $key) {
            $out .= '[' . var_export($key, true) . ']';
        }
        return $out;
    }

    /** PARAM_ELEMENT 的完整路径；非嵌套时也归一成数组。 */
    public function path(): array
    {
        return is_array($this->literal) ? $this->literal : [$this->literal];
    }

    /** 该参数最终要写入的属性名（CONCAT 取第一个可控属性）。 */
    public function targetProperty(): ?string
    {
        if ($this->kind === self::THIS_PROPERTY
            || $this->kind === self::CALLABLE_ARRAY
            || $this->kind === self::ARRAY_ELEMENT) {
            return $this->name;
        }
        if ($this->kind === self::PARAM_ELEMENT && is_string($this->literal)) {
            return $this->literal;
        }
        if ($this->kind === self::CONCAT) {
            return $this->propertyNames()[0] ?? null;
        }
        return null;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::THIS_PROPERTY => '$this->' . $this->name,
            self::VARIABLE => '$' . $this->name,
            self::STRING, self::INT, self::FLOAT, self::BOOL, self::NULL => var_export($this->literal, true),
            self::CONCAT => $this->raw ?? 'concat',
            self::CALLABLE_ARRAY => '[$this->' . $this->name . ', ' . var_export($this->literal, true) . ']',
            self::ARRAY_ELEMENT => '$this->' . $this->name . '[' . (string) $this->literal . ']',
            self::PARAM_ELEMENT => '$' . $this->name . $this->describePath(),
            self::ARG_ELEMENT => '$' . $this->name . $this->describePath(),
            default => $this->raw ?? 'expr',
        };
    }
}
