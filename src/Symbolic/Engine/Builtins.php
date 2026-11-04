<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Security\SanitizerCatalog;
use PopChain\Symbolic\Security\SourceCatalog;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\TaintSource;
use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Value\ArrayValue;
use PopChain\Symbolic\Value\ScalarValue;
use PopChain\Symbolic\Value\UnknownValue;
use PopChain\Symbolic\Value\Value;
use PopChain\Symbolic\Value\ValueFactory;

/**
 * 内置函数语义模型。
 *
 * 返回值建模 + 污点传播规则；sink 检测由 Interpreter 统一负责。
 * call() 返回 null 表示"不是已建模的内置函数"，调用方应继续查找用户函数。
 */
final class Builtins
{
    /** @var array<string, Type> */
    private const RETURN_TYPES = [
        // string
        'trim' => Type::String, 'ltrim' => Type::String, 'rtrim' => Type::String,
        'strtolower' => Type::String, 'strtoupper' => Type::String,
        'ucfirst' => Type::String, 'lcfirst' => Type::String, 'ucwords' => Type::String,
        'substr' => Type::String, 'str_replace' => Type::String, 'str_ireplace' => Type::String,
        'str_repeat' => Type::String, 'str_pad' => Type::String, 'strrev' => Type::String,
        'nl2br' => Type::String, 'wordwrap' => Type::String, 'chunk_split' => Type::String,
        'htmlspecialchars' => Type::String, 'htmlentities' => Type::String,
        'html_entity_decode' => Type::String, 'htmlspecialchars_decode' => Type::String,
        'strip_tags' => Type::String, 'addslashes' => Type::String, 'stripslashes' => Type::String,
        'addcslashes' => Type::String, 'stripcslashes' => Type::String,
        'mysqli_real_escape_string' => Type::String, 'mysqli_escape_string' => Type::String,
        'mysql_real_escape_string' => Type::String, 'pg_escape_string' => Type::String,
        'pg_escape_literal' => Type::String, 'sqlite_escape_string' => Type::String,
        'escapeshellarg' => Type::String, 'escapeshellcmd' => Type::String,
        'basename' => Type::String, 'dirname' => Type::String,
        'sprintf' => Type::String, 'vsprintf' => Type::String,
        'implode' => Type::String, 'join' => Type::String, 'strval' => Type::String,
        'number_format' => Type::String, 'base64_encode' => Type::String,
        'base64_decode' => Type::String, 'urlencode' => Type::String,
        'urldecode' => Type::String, 'rawurlencode' => Type::String, 'rawurldecode' => Type::String,
        'json_encode' => Type::String, 'serialize' => Type::String,
        'http_build_query' => Type::String, 'md5' => Type::String, 'sha1' => Type::String,
        'hash' => Type::String, 'crc32' => Type::String, 'uniqid' => Type::String,
        'chr' => Type::String, 'dechex' => Type::String, 'bin2hex' => Type::String,
        'strstr' => Type::String, 'strrchr' => Type::String, 'strpbrk' => Type::String,
        'str_pad' => Type::String, 'lcfirst' => Type::String,
        // int
        'strlen' => Type::Int, 'strpos' => Type::Int, 'stripos' => Type::Int,
        'strrpos' => Type::Int, 'count' => Type::Int, 'sizeof' => Type::Int,
        'intval' => Type::Int, 'abs' => Type::Int, 'round' => Type::Float,
        'floor' => Type::Float, 'ceil' => Type::Float, 'ord' => Type::Int,
        'time' => Type::Int, 'rand' => Type::Int, 'mt_rand' => Type::Int,
        'hexdec' => Type::Int, 'intdiv' => Type::Int, 'substr_count' => Type::Int,
        'floatval' => Type::Float, 'sqrt' => Type::Float,
        // bool
        'is_int' => Type::Bool, 'is_string' => Type::Bool, 'is_numeric' => Type::Bool,
        'is_array' => Type::Bool, 'is_bool' => Type::Bool, 'is_null' => Type::Bool,
        'is_float' => Type::Bool, 'is_object' => Type::Bool, 'is_callable' => Type::Bool,
        'is_readable' => Type::Bool, 'is_writable' => Type::Bool, 'is_dir' => Type::Bool,
        'is_file' => Type::Bool, 'in_array' => Type::Bool, 'array_key_exists' => Type::Bool,
        'str_contains' => Type::Bool, 'str_starts_with' => Type::Bool, 'str_ends_with' => Type::Bool,
        'preg_match' => Type::Bool, 'file_exists' => Type::Bool, 'function_exists' => Type::Bool,
        'method_exists' => Type::Bool, 'class_exists' => Type::Bool, 'defined' => Type::Bool,
        'version_compare' => Type::Bool, 'password_verify' => Type::Bool,
        'ctype_digit' => Type::Bool, 'ctype_alpha' => Type::Bool, 'ctype_alnum' => Type::Bool,
        'ctype_xdigit' => Type::Bool,
        // array
        'explode' => Type::Array, 'preg_split' => Type::Array, 'str_split' => Type::Array,
        'array_merge' => Type::Array, 'array_keys' => Type::Array, 'array_values' => Type::Array,
        'array_slice' => Type::Array, 'array_map' => Type::Array, 'array_filter' => Type::Array,
        'array_unique' => Type::Array, 'array_reverse' => Type::Array, 'array_combine' => Type::Array,
        'array_fill' => Type::Array, 'range' => Type::Array, 'array_count_values' => Type::Array,
        'array_diff' => Type::Array, 'array_intersect' => Type::Array, 'array_flip' => Type::Array,
        'json_decode' => Type::Mixed, 'unserialize' => Type::Mixed,
        'parse_url' => Type::Mixed, 'pathinfo' => Type::Mixed,
        'filter_var' => Type::Mixed, 'getenv' => Type::String,
        'file_get_contents' => Type::Mixed, 'fgets' => Type::Mixed, 'fread' => Type::Mixed,
        'stream_get_contents' => Type::Mixed, 'array_pop' => Type::Mixed,
        'array_shift' => Type::Mixed, 'reset' => Type::Mixed, 'current' => Type::Mixed,
        'end' => Type::Mixed, 'constant' => Type::Mixed,
    ];

    /**
     * 返回值与输入无关的函数：结果不应携带污点。
     *
     * @var array<string, true>
     */
    private const NO_TAINT = [
        'strlen' => true, 'strpos' => true, 'stripos' => true, 'strrpos' => true,
        'count' => true, 'sizeof' => true, 'substr_count' => true,
        'intval' => true, 'floatval' => true, 'boolval' => true,
        'abs' => true, 'round' => true, 'floor' => true, 'ceil' => true, 'sqrt' => true,
        'ord' => true, 'chr' => true, 'time' => true, 'rand' => true, 'mt_rand' => true,
        'uniqid' => true, 'md5' => true, 'sha1' => true, 'hash' => true, 'crc32' => true,
        'bin2hex' => true, 'dechex' => true, 'hexdec' => true, 'intdiv' => true,
        'is_int' => true, 'is_string' => true, 'is_numeric' => true, 'is_array' => true,
        'is_bool' => true, 'is_null' => true, 'is_float' => true, 'is_object' => true,
        'is_callable' => true, 'is_readable' => true, 'is_writable' => true,
        'is_dir' => true, 'is_file' => true, 'in_array' => true, 'array_key_exists' => true,
        'file_exists' => true, 'function_exists' => true, 'method_exists' => true,
        'class_exists' => true, 'defined' => true, 'version_compare' => true,
        'password_verify' => true, 'ctype_digit' => true, 'ctype_alpha' => true,
        'ctype_alnum' => true, 'ctype_xdigit' => true,
    ];

    /**
     * @param list<Value> $args
     */
    public function call(string $name, array $args, State $state): ?Value
    {
        $lower = strtolower($name);

        // ------------------------------------------------------------------
        // 特殊建模
        // ------------------------------------------------------------------
        switch ($lower) {
            case 'file_get_contents':
                $path = $this->constantString($args[0] ?? null);
                if ($path !== null && str_starts_with($path, 'php://input')) {
                    return ValueFactory::fromInput('php://input', 'INPUT');
                }

                return $this->scalar($name, $args, Type::String);

            case 'getenv':
                $variable = $this->constantString($args[0] ?? null);
                $expression = $variable !== null ? "getenv('" . $variable . "')" : 'getenv()';

                return new ScalarValue(
                    new VarExpr($expression, Type::String),
                    Taint::of(new TaintSource('ENV', $expression)),
                );

            case 'getallheaders':
            case 'apache_request_headers':
                return new ArrayValue([], true, $lower . '()', 'HEADER');

            case 'parse_url':
            case 'pathinfo':
                return $this->arrayResult($name, $args, $this->taintOf($args));

            case 'json_decode':
            case 'unserialize':
                $taint = $this->taintOf($args);
                $element = new ScalarValue(
                    new VarExpr(ValueFactory::freshName($lower . '-element'), Type::Mixed),
                    $taint,
                );

                return new ArrayValue([], true, null, null, $taint, $element);

            case 'explode':
            case 'preg_split':
            case 'str_split':
            case 'file':
            case 'array_keys':
            case 'array_values':
            case 'array_merge':
            case 'array_slice':
            case 'array_map':
            case 'array_filter':
            case 'array_unique':
            case 'array_reverse':
            case 'array_combine':
            case 'array_fill':
            case 'range':
            case 'array_count_values':
            case 'array_diff':
            case 'array_intersect':
            case 'array_flip':
                return $this->arrayFunction($name, $lower, $args);

            case 'implode':
            case 'join':
                $taint = $this->taintOf($args);
                foreach ($args as $arg) {
                    if ($arg instanceof ArrayValue) {
                        $taint = $taint->union($arg->taint);
                    }
                }

                return new ScalarValue(
                    Expr::call($name, $this->exprArgs($args), Type::String),
                    $taint,
                );

            case 'filter_var':
                $taint = $this->taintOf($args);
                if (isset($args[1]) && $args[1]->toExpr()->isConstant()) {
                    $filter = $args[1]->toExpr()->constantValue();
                    if (in_array($filter, [FILTER_SANITIZE_NUMBER_INT, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_VALIDATE_INT, FILTER_VALIDATE_BOOLEAN], true)) {
                        $taint = $taint->sanitizeFor(['ALL']);
                    } elseif (in_array($filter, [
                        FILTER_SANITIZE_SPECIAL_CHARS,
                        FILTER_SANITIZE_FULL_SPECIAL_CHARS,
                    ], true)) {
                        $taint = $taint->sanitizeFor(['XSS']);
                    }
                }

                return new ScalarValue(
                    Expr::call($name, $this->exprArgs($args), Type::Mixed),
                    $taint,
                );

            case 'htmlspecialchars_decode':
            case 'html_entity_decode':
                return new ScalarValue(
                    Expr::call($name, $this->exprArgs($args), Type::String),
                    $this->taintOf($args)->unsanitizeFor(['XSS']),
                );

            case 'compact':
            case 'array_pop':
            case 'array_shift':
            case 'reset':
            case 'current':
            case 'end':
            case 'constant':
                return new UnknownValue(ValueFactory::freshName($lower), $this->taintOf($args));
        }

        // ------------------------------------------------------------------
        // 通用返回类型 + 污点规则
        // ------------------------------------------------------------------
        if (isset(self::NO_TAINT[$lower])) {
            $expr = Expr::call($name, $this->exprArgs($args), self::RETURN_TYPES[$lower] ?? Type::Mixed);

            return new ScalarValue($expr, Taint::none());
        }

        if (!isset(self::RETURN_TYPES[$lower])) {
            return null;
        }

        $taint = $this->taintOf($args);
        $categories = SanitizerCatalog::categoriesFor($lower);
        if ($categories !== []) {
            $taint = $taint->sanitizeFor($categories);
        }

        return new ScalarValue(
            Expr::call($name, $this->exprArgs($args), self::RETURN_TYPES[$lower]),
            $taint,
        );
    }

    /**
     * @param list<Value> $args
     */
    private function scalar(string $name, array $args, Type $type): ScalarValue
    {
        return new ScalarValue(
            Expr::call($name, $this->exprArgs($args), $type),
            $this->taintOf($args),
        );
    }

    /**
     * @param list<Value> $args
     */
    private function arrayFunction(string $name, string $lower, array $args): Value
    {
        $taint = $this->taintOf($args);

        if ($lower === 'array_merge' && $args !== []) {
            $items = [];
            $allArrays = true;
            foreach ($args as $arg) {
                if (!$arg instanceof ArrayValue) {
                    $allArrays = false;
                    break;
                }
                foreach ($arg->items() as $key => $value) {
                    $items[is_int($key) ? $key : $key] = $value;
                }
            }
            if ($allArrays) {
                $element = $items !== [] ? ValueFactory::merge(array_values($items)) : null;

                return new ArrayValue($items, false, null, null, $taint, $element);
            }
        }

        if ($lower === 'array_values' && isset($args[0]) && $args[0] instanceof ArrayValue) {
            return new ArrayValue(array_values($args[0]->items()), false, null, null, $taint, $args[0]->elementValue());
        }

        if ($lower === 'array_keys' && isset($args[0]) && $args[0] instanceof ArrayValue) {
            $keys = [];
            foreach (array_keys($args[0]->items()) as $key) {
                $keys[] = ValueFactory::fromConstant($key);
            }
            if ($keys !== []) {
                return new ArrayValue($keys, $args[0]->isOpen(), null, null, $taint);
            }
        }

        return $this->arrayResult($name, $args, $taint);
    }

    /**
     * @param list<Value> $args
     */
    private function arrayResult(string $name, array $args, Taint $taint): ArrayValue
    {
        $element = new ScalarValue(
            new VarExpr(ValueFactory::freshName($name . '-element'), Type::Mixed),
            $taint,
        );

        return new ArrayValue([], true, null, null, $taint, $element);
    }

    /**
     * @param list<Value> $args
     * @return list<Expr>
     */
    private function exprArgs(array $args): array
    {
        return array_map(
            static fn (Value $value): Expr => $value->toExpr(),
            $args,
        );
    }

    /**
     * @param list<Value> $args
     */
    private function taintOf(array $args): Taint
    {
        $taint = Taint::none();
        foreach ($args as $value) {
            $taint = $taint->union($value->taint);
            if ($value instanceof ArrayValue) {
                $taint = $taint->union($value->elementValue()->taint);
            }
        }

        return $taint;
    }

    private function constantString(?Value $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $expr = $value->toExpr();

        return $expr->isConstant() && is_string($expr->constantValue()) ? $expr->constantValue() : null;
    }
}
