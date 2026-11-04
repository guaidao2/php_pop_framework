<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

/**
 * Sink 目录。
 *
 * 名称使用小写；方法调用使用 "method:" 前缀单独匹配，避免把
 * 普通函数 query() 也当成 SQL 执行。
 */
final class SinkCatalog
{
    /** @var array<string, Sink>|null */
    private static ?array $sinks = null;

    public static function lookupFunction(string $name): ?Sink
    {
        self::init();

        return self::$sinks[strtolower($name)] ?? null;
    }

    public static function lookupMethod(string $name): ?Sink
    {
        self::init();
        $name = strtolower($name);
        $sqlMethods = ['query', 'exec', 'prepare', 'rawquery', 'multi_query'];
        if (in_array($name, $sqlMethods, true)) {
            return new Sink('method:' . $name, 'SQLI', 'critical', 'SQL Injection', [0], '\' OR \'1\'=\'1');
        }
        $rawQueryMethods = ['whereraw', 'orderbyraw', 'havingraw', 'selectraw', 'groupbyraw'];
        if (in_array($name, $rawQueryMethods, true)) {
            return new Sink('method:' . $name, 'SQLI', 'critical', 'SQL Injection (raw query fragment)', [0], '\' OR \'1\'=\'1');
        }
        if (in_array($name, ['loadxml', 'load'], true)) {
            return new Sink('method:' . $name, 'XXE', 'high', 'XML External Entity', [0], '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
        }

        return null;
    }

    /**
     * @return array<string, Sink>
     */
    public static function all(): array
    {
        self::init();

        return self::$sinks;
    }

    private static function init(): void
    {
        if (self::$sinks !== null) {
            return;
        }

        $sinks = [];
        $add = static function (Sink $sink) use (&$sinks): void {
            $sinks[$sink->id] = $sink;
        };

        // XSS
        foreach (['echo', 'print', 'printf', 'print_r', 'var_dump', 'vprintf'] as $name) {
            $add(new Sink($name, 'XSS', 'high', 'Reflected XSS', [], '<script>alert(document.domain)</script>'));
        }

        // 命令执行
        foreach (['system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'expect_popen'] as $name) {
            $add(new Sink($name, 'RCE', 'critical', 'OS Command Injection', [0], 'id'));
        }

        // 文件包含
        $add(new Sink('include', 'LFI', 'high', 'Local File Inclusion', [], '../../../../etc/passwd'));
        $add(new Sink('require', 'LFI', 'high', 'Local File Inclusion', [], '../../../../etc/passwd'));

        // 代码执行
        foreach (['eval', 'assert'] as $name) {
            $add(new Sink($name, 'CODE_EXEC', 'critical', 'Code Injection', [0], 'phpinfo();'));
        }
        // create_function 的代码在第二个参数
        $add(new Sink('create_function', 'CODE_EXEC', 'critical', 'Code Injection', [], 'phpinfo();'));
        $add(new Sink('preg_replace', 'CODE_EXEC', 'high', 'Code Injection (preg_replace /e)', [], 'phpinfo()'));
        foreach (['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'] as $name) {
            // 只有第一个参数（被调用者）的污点才构成动态调用风险；
            // 后续参数会通过实际被调用函数的建模单独检查。
            $add(new Sink($name, 'CODE_EXEC', 'high', 'Dynamic Function Call', [0], 'system'));
        }

        // 反序列化
        $add(new Sink('unserialize', 'OBJECT_INJECTION', 'high', 'PHP Object Injection', [0], 'O:8:"stdClass":0:{}'));

        // SQL 注入
        foreach ([
            'mysqli_query' => 1,
            'mysqli_multi_query' => 1,
            'mysql_query' => 0,
            'pg_query' => 1,
            'pg_send_query' => 1,
            'sqlite_query' => 1,
            'sqlite_exec' => 1,
            'db2_exec' => 1,
            'odbc_exec' => 1,
            'sqlsrv_query' => 1,
        ] as $name => $index) {
            $add(new Sink($name, 'SQLI', 'critical', 'SQL Injection', [$index], '\' OR \'1\'=\'1'));
        }

        // SSRF / 远程文件读取
        foreach (['file_get_contents', 'readfile', 'fopen', 'curl_init', 'get_headers', 'fsockopen', 'stream_socket_client'] as $name) {
            $add(new Sink($name, 'SSRF', 'high', 'Server-Side Request Forgery', [0], 'http://127.0.0.1:80/'));
        }

        // 文件写入 / 删除
        foreach (['file_put_contents' => [0], 'unlink' => [0], 'rename' => [], 'copy' => [], 'mkdir' => [0], 'rmdir' => [0], 'chmod' => [0], 'chown' => [0], 'move_uploaded_file' => []] as $name => $indexes) {
            $add(new Sink($name, 'PATH_TRAVERSAL', 'high', 'Path Traversal / Arbitrary File Write', $indexes, '../../../../tmp/pwned'));
        }

        // XXE
        foreach (['simplexml_load_string', 'simplexml_load_file'] as $name) {
            $add(new Sink($name, 'XXE', 'high', 'XML External Entity', [0], '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'));
        }

        // 响应头注入
        foreach (['header', 'setcookie', 'setrawcookie', 'mail'] as $name) {
            $add(new Sink($name, 'HEADER', 'medium', 'Header Injection', [], '%0d%0aSet-Cookie: pwned=1'));
        }

        self::$sinks = $sinks;
    }
}
