<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

use PopChain\Symbolic\Symbolic\TaintSource;
use PopChain\Symbolic\Value\ArrayValue;
use PopChain\Symbolic\Value\Value;
use PopChain\Symbolic\Value\ValueFactory;

/**
 * 输入源目录。
 *
 * 默认把 PHP 的超全局变量视为污点源。CLI 参数 $argv 也可通过
 * 配置启用（默认开启）。
 */
final class SourceCatalog
{
    /** @var array<string, string> */
    private const SUPER_GLOBALS = [
        '$_GET' => 'GET',
        '$_POST' => 'POST',
        '$_REQUEST' => 'REQUEST',
        '$_COOKIE' => 'COOKIE',
        '$_SERVER' => 'SERVER',
        '$_FILES' => 'FILES',
        '$_ENV' => 'ENV',
    ];

    /**
     * 生成初始环境中的超全局变量。
     *
     * @return array<string, Value>
     */
    public static function superglobals(bool $argvAsSource = true): array
    {
        $env = [];
        foreach (self::SUPER_GLOBALS as $name => $kind) {
            $env[$name] = new ArrayValue([], true, $name, $kind);
        }

        if ($argvAsSource) {
            $env['$argv'] = new ArrayValue([], true, '$argv', 'CLI');
        }

        return $env;
    }

    public static function kindFromExpression(string $expression): string
    {
        foreach (self::SUPER_GLOBALS as $name => $kind) {
            if (str_starts_with($expression, $name)) {
                return $kind;
            }
        }
        if (str_starts_with($expression, '$argv')) {
            return 'CLI';
        }

        return 'UNKNOWN';
    }

    /**
     * 是否为可直接识别的输入表达式。
     */
    public static function isSourceExpression(string $expression): bool
    {
        if (str_starts_with($expression, 'php://input')) {
            return true;
        }
        foreach (array_keys(self::SUPER_GLOBALS) as $name) {
            if (str_starts_with($expression, $name)) {
                return true;
            }
        }

        return str_starts_with($expression, '$argv');
    }

    public static function source(string $expression, ?string $kind = null): TaintSource
    {
        return new TaintSource($kind ?? self::kindFromExpression($expression), $expression);
    }
}
