<?php
declare(strict_types=1);

namespace PopChain\Model;

/**
 * 危险函数与"字符串上下文"函数的统一目录。
 *
 * 两件事放在一张表里维护：
 *   1. SINKS：函数短名 => [危险参数位置, 分类]，供 SinkScanner 使用；
 *   2. STRING_POSITIONS：哪些参数会被 PHP 当字符串处理，从而隐式触发 __toString。
 *      null 表示全部实参，空数组表示 none。
 */
final class SinkCatalog
{
    /** @var array<string, array{0:list<int>,1:string}> */
    private const SINKS = [
        'system' => [[0], 'rce'],
        'exec' => [[0], 'rce'],
        'shell_exec' => [[0], 'rce'],
        'passthru' => [[0], 'rce'],
        'popen' => [[0], 'rce'],
        'proc_open' => [[0], 'rce'],
        'pcntl_exec' => [[0], 'rce'],
        'assert' => [[0], 'rce'],
        'create_function' => [[1], 'rce'],
        'eval' => [[0], 'rce'],
        'call_user_func' => [[0], 'callable'],
        'call_user_func_array' => [[0], 'callable'],
        'array_map' => [[0], 'callable'],
        'array_filter' => [[1], 'callable'],
        'array_walk' => [[1], 'callable'],
        'array_reduce' => [[1], 'callable'],
        'usort' => [[1], 'callable'],
        'uasort' => [[1], 'callable'],
        'uksort' => [[1], 'callable'],
        'preg_replace_callback' => [[1], 'callable'],
        'file_put_contents' => [[0, 1], 'file_write'],
        'fwrite' => [[1], 'file_write'],
        'fputs' => [[1], 'file_write'],
        'fputcsv' => [[1], 'file_write'],
        'unlink' => [[0], 'file_delete'],
        'rename' => [[0, 1], 'file_move'],
        'copy' => [[0, 1], 'file_copy'],
        'chmod' => [[0], 'file_perms'],
        'symlink' => [[0, 1], 'file_move'],
        'move_uploaded_file' => [[0, 1], 'file_move'],
        'include' => [[0], 'include'],
        'include_once' => [[0], 'include'],
        'require' => [[0], 'include'],
        'require_once' => [[0], 'include'],
        'unserialize' => [[0], 'deserialize'],
        'file_get_contents' => [[0], 'file_read'],
        'fopen' => [[0], 'file_read'],
        'readfile' => [[0], 'file_read'],
        'file' => [[0], 'file_read'],
        'highlight_file' => [[0], 'file_read'],
        'show_source' => [[0], 'file_read'],
        'simplexml_load_file' => [[0], 'ssrf'],
        'get_headers' => [[0], 'ssrf'],
        'fsockopen' => [[0], 'ssrf'],
        'stream_socket_client' => [[0], 'ssrf'],
        'mail' => [[0], 'mail'],
        'putenv' => [[0], 'env'],
        'parse_str' => [[0], 'var'],
    ];

    /**
     * 非 sink 但会把参数按字符串处理的内置函数。
     *
     * @var array<string, list<int>|null>
     */
    private const STRING_POSITIONS = [
        'strlen' => [0], 'strpos' => [0, 1], 'stripos' => [0, 1], 'strrpos' => [0, 1],
        'strcmp' => [0, 1], 'strncmp' => [0, 1], 'strcasecmp' => [0, 1],
        'substr' => [0], 'trim' => [0], 'ltrim' => [0], 'rtrim' => [0],
        'explode' => [0, 1], 'implode' => [0, 1],
        'str_replace' => [0, 1, 2], 'str_ireplace' => [0, 1, 2],
        'strtolower' => [0], 'strtoupper' => [0], 'ucfirst' => [0], 'ucwords' => [0],
        'vsprintf' => [0],
        'preg_match' => [0, 1], 'preg_match_all' => [0, 1],
        'preg_replace' => [0, 1, 2], 'preg_split' => [0, 1], 'preg_quote' => [0],
        'md5' => [0], 'sha1' => [0], 'hash' => [1], 'crc32' => [0],
        'base64_encode' => [0], 'base64_decode' => [0],
        'urlencode' => [0], 'urldecode' => [0],
        'htmlspecialchars' => [0], 'htmlentities' => [0], 'addslashes' => [0],
        'json_decode' => [0], 'unserialize' => [0],
        'strval' => [0], 'str_contains' => [0, 1], 'str_starts_with' => [0, 1], 'str_ends_with' => [0, 1],
        'strip_tags' => [0], 'nl2br' => [0], 'wordwrap' => [0],
        'fwrite' => [1], 'fputs' => [1],
        'json_encode' => [], 'serialize' => [], 'print_r' => [], 'var_dump' => [],
        // sink 函数里真正按字符串处理的参数
        'system' => [0], 'exec' => [0], 'shell_exec' => [0], 'passthru' => [0],
        'popen' => [0], 'proc_open' => [0], 'pcntl_exec' => [0],
        'assert' => [0], 'create_function' => [0, 1],
        'file_put_contents' => [0, 1], 'file_get_contents' => [0], 'fopen' => [0],
        'readfile' => [0], 'file' => [0], 'highlight_file' => [0], 'show_source' => [0],
        'unlink' => [0], 'rename' => [0, 1], 'copy' => [0, 1], 'chmod' => [0],
        'symlink' => [0, 1], 'move_uploaded_file' => [0, 1],
        'include' => [0], 'include_once' => [0], 'require' => [0], 'require_once' => [0],
        'unserialize' => [0], 'putenv' => [0], 'parse_str' => [0],
        'mail' => [0, 1, 2, 3],
        // 资源 / 数组参数，不会触发 __toString
        'fputcsv' => [],
        'array_map' => [], 'array_filter' => [], 'array_walk' => [], 'array_reduce' => [],
        'usort' => [], 'uasort' => [], 'uksort' => [], 'preg_replace_callback' => [],
        'call_user_func' => [], 'call_user_func_array' => [],
    ];

    /** @return array<string, array{0:list<int>,1:string}> */
    public static function all(): array
    {
        return self::SINKS;
    }

    /** @return array{0:list<int>,1:string}|null */
    public static function match(string $function): ?array
    {
        return self::SINKS[strtolower(self::shortName($function))] ?? null;
    }

    /**
     * 该函数的哪些实参会被当作字符串（可能触发 __toString）。
     * null = 全部实参，[] = 没有。
     *
     * @return list<int>|null
     */
    public static function stringPositions(string $function): ?array
    {
        $short = strtolower(self::shortName($function));

        if (array_key_exists($short, self::STRING_POSITIONS)) {
            return self::STRING_POSITIONS[$short];
        }

        // 未知函数不做猜测：只有显式登记的位置才算字符串上下文
        return [];
    }

    private static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
