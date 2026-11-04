<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Expr;

use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 函数调用结果表达式。
 *
 * 这里只描述"返回值是什么"，内联执行与污点规则由引擎负责。
 */
final class CallExpr extends Expr
{
    /**
     * 可以在分析期安全地做常量求值的纯函数。
     *
     * @var array<string, true>
     */
    private const PURE_FUNCTIONS = [
        'strlen' => true, 'strtolower' => true, 'strtoupper' => true,
        'trim' => true, 'ltrim' => true, 'rtrim' => true, 'substr' => true,
        'str_replace' => true, 'str_ireplace' => true, 'str_contains' => true,
        'str_starts_with' => true, 'str_ends_with' => true, 'strpos' => true,
        'stripos' => true, 'strrpos' => true, 'strrev' => true,
        'intval' => true, 'floatval' => true, 'strval' => true, 'boolval' => true,
        'abs' => true, 'round' => true, 'floor' => true, 'ceil' => true,
        'max' => true, 'min' => true, 'count' => true, 'sizeof' => true,
        'implode' => true, 'join' => true, 'explode' => true, 'array_merge' => true,
        'in_array' => true, 'array_key_exists' => true, 'array_keys' => true,
        'array_values' => true, 'array_map' => true, 'array_filter' => true,
        'md5' => true, 'sha1' => true, 'crc32' => true, 'hash' => true,
        'base64_encode' => true, 'base64_decode' => true,
        'urlencode' => true, 'rawurlencode' => true, 'urldecode' => true,
        'rawurldecode' => true, 'json_encode' => true, 'json_decode' => true,
        'sprintf' => true, 'vsprintf' => true, 'number_format' => true,
        'str_repeat' => true, 'str_pad' => true, 'ucfirst' => true,
        'lcfirst' => true, 'ucwords' => true, 'nl2br' => true,
        'htmlspecialchars' => true, 'htmlentities' => true, 'strip_tags' => true,
        'addslashes' => true, 'stripslashes' => true,
        'escapeshellarg' => true, 'escapeshellcmd' => true,
        'preg_match' => true, 'preg_replace' => true, 'preg_quote' => true,
        'is_int' => true, 'is_string' => true, 'is_numeric' => true,
        'is_array' => true, 'is_bool' => true, 'is_null' => true,
        'ctype_digit' => true, 'ctype_alpha' => true, 'ctype_alnum' => true,
        'chr' => true, 'ord' => true, 'dechex' => true, 'hexdec' => true,
        'intdiv' => true, 'pow' => true, 'sqrt' => true,
    ];

    /**
     * @param list<Expr> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        private readonly Type $type = Type::Mixed,
    ) {
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function toString(): string
    {
        return $this->name . '(' . implode(', ', array_map(
            static fn (Expr $arg): string => $arg->toString(),
            $this->args,
        )) . ')';
    }

    public function canonical(): string
    {
        return 'f:' . $this->name . '(' . implode(',', array_map(
            static fn (Expr $arg): string => $arg->canonical(),
            $this->args,
        )) . ')';
    }

    public function children(): array
    {
        return $this->args;
    }

    public function isPure(): bool
    {
        if (!isset(self::PURE_FUNCTIONS[strtolower($this->name)])) {
            return false;
        }
        foreach ($this->args as $arg) {
            if (!$arg->isPure()) {
                return false;
            }
        }

        return true;
    }

    public function evaluate(array $env = []): mixed
    {
        $function = strtolower($this->name);
        if (!isset(self::PURE_FUNCTIONS[$function])) {
            return Unknown::value();
        }

        $values = [];
        foreach ($this->args as $arg) {
            $value = $arg->evaluate($env);
            if ($value === Unknown::value()) {
                return Unknown::value();
            }
            $values[] = $value;
        }

        try {
            return $function(...$values);
        } catch (\Throwable) {
            return Unknown::value();
        }
    }
}
