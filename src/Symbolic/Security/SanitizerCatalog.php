<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

/**
 * 净化器目录：函数名 => 可以清除的漏洞类别。
 *
 * 这些规则是"按常见用法的保守判断"：
 *   - intval 等数值化函数清除全部注入类污点
 *   - htmlspecialchars 只清除 XSS
 *   - addslashes 只清除 SQLI
 */
final class SanitizerCatalog
{
    /** @var array<string, list<string>> */
    private const SANITIZERS = [
        'intval' => ['ALL'],
        'floatval' => ['ALL'],
        'boolval' => ['ALL'],
        'settype' => ['ALL'],
        'htmlspecialchars' => ['XSS'],
        'htmlentities' => ['XSS'],
        'strip_tags' => ['XSS'],
        'addslashes' => ['SQLI'],
        'mysqli_real_escape_string' => ['SQLI'],
        'mysqli_escape_string' => ['SQLI'],
        'mysql_real_escape_string' => ['SQLI'],
        'pg_escape_string' => ['SQLI'],
        'pg_escape_literal' => ['SQLI'],
        'sqlite_escape_string' => ['SQLI'],
        'escapeshellarg' => ['RCE'],
        'escapeshellcmd' => ['RCE'],
        'basename' => ['LFI', 'PATH_TRAVERSAL'],
        'preg_quote' => ['REGEX'],
        // urlencode / rawurlencode 会把引号、尖括号等百分号编码，HTML 属性/文本上下文无法逃逸
        'urlencode' => ['HEADER', 'XSS'],
        'rawurlencode' => ['HEADER', 'XSS'],
        'realpath' => ['SSRF'],
    ];

    /**
     * @return list<string>
     */
    public static function categoriesFor(string $function): array
    {
        return self::SANITIZERS[strtolower($function)] ?? [];
    }

    public static function isSanitizer(string $function): bool
    {
        return isset(self::SANITIZERS[strtolower($function)]);
    }
}
