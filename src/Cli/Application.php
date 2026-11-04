<?php
declare(strict_types=1);

namespace PopChain\Cli;

use PopChain\Analysis\ChainFinder;
use PopChain\Analysis\SinkScanner;
use PopChain\Detector\DetectorRegistry;
use PopChain\Detector\Finding;
use PopChain\Engine\EngineRegistry;
use PopChain\Model\Chain;
use PopChain\Parser\ProjectParser;
use PopChain\Payload\PayloadBuilder;

/**
 * 统一 CLI 入口。根目录 popchain 与 bin/popchain 都调用这里。
 */
final class Application
{
    public const VERSION = '2.8.5';

    private const DEFAULT_ENTRIES = ['__destruct', '__wakeup', '__unserialize'];

    /** --entry=all 时启用的全部魔术方法入口。 */
    private const ALL_ENTRIES = [
        '__destruct', '__wakeup', '__unserialize',
        '__toString', '__invoke', '__call', '__get', '__isset', '__clone',
    ];

    /** 不带值的布尔选项。 */
    private const BOOLEAN_OPTIONS = ['json', 'stdin', 'include-vendor', 'obfuscate', 'list-types', 'sym-no-includes', 'no-gadget-gate', 'color', 'no-color', 'help', 'version'];

    /** 必须是正整数的选项。 */
    private const NUMERIC_OPTIONS = ['max-depth', 'max-chains'];

    /** 反序列化时会被自动调用的入口；其余魔术方法需要外部触发。 */
    private const DESERIALIZE_ENTRIES = ['__destruct', '__wakeup', '__unserialize'];

    /** sink 分类 => 对应取值选项；未写时再用 DEFAULT_VALUES 的默认值。 */
    private const CATEGORY_OPTIONS = [
        'rce' => 'pop-cmd',
        'file_read' => 'pop-read-file',
        'include' => 'pop-read-file',
        'file_write' => 'pop-write-file',
        'file_delete' => 'pop-delete-file',
        'file_move' => 'pop-move-file',
        'file_copy' => 'pop-move-file',
        'file_perms' => 'pop-chmod-file',
        'ssrf' => 'pop-ssrf-url',
        'env' => 'pop-env-value',
        'var' => 'pop-var-value',
        'mail' => 'pop-mail-to',
        'deserialize' => 'pop-serialized',
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
            'not_raw_source' => 'URL returned an HTML page instead of raw PHP source: {url} (the page probably executes PHP or uses highlight_file)',
            'html_source_extracted' => '[i] URL looks like highlighted PHP source; extracted the source code from the HTML',
            'bad_encode' => '--encode only accepts url or base64, got: {value}',
            'available_types' => 'Available detection types:',
            'all_types' => '(default) run deserialization + symbolic (dom-xss is opt-in)',
            'no_findings' => 'No findings.',
            'no_pop_chain' => 'no POP chain in the scanned code (check autoloaded or loaded classes)',
            'unknown_type' => 'Unknown detection type: {type}. Available: {types}',
            'engine_infeasible' => 'engine marked chain as infeasible, skipped: {entry}',
            'engine_unavailable' => 'engine is not available, falling back to heuristics: {engine}',
            'path_missing' => 'Path not found: {path}',
            'bad_numeric' => '--{option} must be a positive integer, got: {value}',
            'no_classes' => 'No PHP classes found in: {path}',
            'output_failed' => 'Cannot write output file: {file}',
            'external_entry' => 'note: this entry needs an external trigger; unserializing the payload alone will not call it',
            'incomplete_payload' => 'note: the sink value could not be placed in the payload; adjust the chain by hand',
            'gadgets_suppressed' => 'note: {count} POP chain(s) suppressed because no tainted unserialize() input was found; use --type=deserialization to list gadget chains',
            'gadget_unverified' => 'note: found an unserialize() candidate, but symbolic analysis could not confirm it; verify this gadget chain before treating it as exploitable',
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
            'not_raw_source' => 'URL 返回的是 HTML 页面，不是 PHP 源码: {url}（页面可能执行了 PHP，或用了 highlight_file）',
            'html_source_extracted' => '[i] URL 看起来是高亮后的 PHP 源码页，已从 HTML 里提取出源代码',
            'bad_encode' => '--encode 只接受 url 或 base64，当前值: {value}',
            'available_types' => '可用的检测类型:',
            'all_types' => '（默认）运行 deserialization + symbolic（dom-xss 需显式启用）',
            'no_findings' => '没有发现。',
            'no_pop_chain' => '扫描范围内没有可用 POP 链（注意 autoload 或运行时加载的类）',
            'unknown_type' => '未知检测类型: {type}，可用: {types}',
            'engine_infeasible' => '引擎判定链不可行，已跳过: {entry}',
            'engine_unavailable' => '引擎不可用，回退到启发式: {engine}',
            'path_missing' => '路径不存在: {path}',
            'bad_numeric' => '--{option} 必须是正整数，当前值: {value}',
            'no_classes' => '没有在 {path} 里找到 PHP 类',
            'output_failed' => '无法写入输出文件: {file}',
            'external_entry' => '注意：该入口需要外部触发，单独反序列化 payload 不会调用它',
            'incomplete_payload' => '注意：sink 的值没能写进 payload，需要手工调整这条链',
            'gadgets_suppressed' => '注意：未发现受污点控制的 unserialize() 入口，已抑制 {count} 条 POP 链；可用 --type=deserialization 查看 gadget 链',
            'gadget_unverified' => '注意：找到了 unserialize() 候选入口，但符号执行未能确认；确认这条 gadget 链可利用前先核对入口',
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

        if (isset($options['list-types'])) {
            echo self::t('available_types'), "\n";
            echo '  all  ', self::t('all_types'), "\n";
            foreach (DetectorRegistry::describe() as $name => $description) {
                echo '  ', $name, '  ', $description, "\n";
            }
            return 0;
        }

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

        $encode = strtolower((string) self::option($options, 'encode', ''));
        if ($encode !== '' && !in_array($encode, ['url', 'base64'], true)) {
            fwrite(STDERR, '[!] ' . self::t('bad_encode', ['value' => $encode]) . "
");
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

            $source = self::extractPhpSource($code);
            if ($source === null) {
                throw new \RuntimeException(self::t('not_raw_source', ['url' => $url]));
            }
            if ($source !== $code) {
                fwrite(STDERR, self::t('html_source_extracted') . "\n");
            }

            $path = self::temporaryFile($source);
            return [$path, $url, $path];
        }

        $path = $positional[1] ?? '.';
        if (!file_exists($path)) {
            throw new \RuntimeException(self::t('path_missing', ['path' => $path]));
        }
        return [$path, $path, null];
    }

    /**
     * 从 URL 内容里得到 PHP 源码：
     *   - 本来就是源码：原样返回；
     *   - highlight_file/highlight_string 渲染出来的 HTML：去掉标签、反转义后还原源码；
     *   - 其它 HTML：返回 null，交由调用方提示"不是源码"。
     */
    public static function extractPhpSource(string $content): ?string
    {
        if (str_contains($content, '<?php') || str_contains($content, '<?PHP')) {
            return $content;
        }

        $looksHighlighted = preg_match('/&lt;\?php/i', $content) === 1
            || preg_match('/<span[^>]*>&lt;\?/i', $content) === 1;
        if (!$looksHighlighted) {
            return null;
        }

        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_contains($text, '<?php') || str_contains($text, '<?PHP')) {
            return $text;
        }
        return null;
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
  --pop-cmd=value          Value for RCE sinks (default: id)
  --pop-read-file=path     Value for file read / include sinks (default: /etc/passwd)
  --pop-write-file=path    Value for file write sinks (default: /tmp/popchain.txt)
  --pop-delete-file=path   Value for file delete sinks (default: /tmp/popchain.txt)
  --pop-move-file=path     Value for file move / copy sinks (default: /tmp/popchain.txt)
  --pop-chmod-file=path    Value for chmod sinks (default: /tmp/popchain.txt)
  --pop-ssrf-url=url       Value for SSRF sinks such as simplexml_load_file / get_headers (default: http://127.0.0.1/)
  --pop-env-value=text     Value for putenv sinks (default: POPCHAIN=1)
  --pop-var-value=text     Value for parse_str sinks (default: popchain=1)
  --pop-mail-to=address    Value for mail sinks (default: a@b.test)
  --pop-serialized=data    Value for unserialize sinks (default: a:0:{})
  --output=file        Write payloads to a file (JSON in --json mode)
  --max-depth=8        Maximum chain depth
  --max-chains=50      Maximum number of chains
  --include-vendor     Also scan vendor/
  --json               JSON output
  --color              Force ANSI colors even when stdout is not a TTY
  --no-color           Disable colors (NO_COLOR also works)
  --type=all|deserialization|symbolic|dom-xss  Detection types to run (default: all = deserialization + symbolic; dom-xss is opt-in)
  --no-gadget-gate     Keep POP chains even when no tainted unserialize() input is found (directory scans)
  --list-types         List available detection types and exit
  --obfuscate          Emit strings as S: hex escapes, hiding keywords from regex filters
  --encode=url|base64  Encode the payload for copy/paste: url for ?data=, base64 for log-safe text
                       (--output writes the encoded form as well)
  --engine=none|external  Engine override: none disables the built-in solver, external uses --engine-cmd
  --engine-cmd=cmd     External engine command: JSON request on stdin, JSON response on stdout
  --engine-timeout=sec Engine timeout in seconds (default: 10)
  --sym-timeout=sec    Symbolic engine time limit in seconds (default: 30)
  --sym-max-paths=N    Symbolic engine max explored paths (default: 2000)
  --sym-max-states=N   Max states per execution point (default: 128)
  --sym-max-depth=N    Max inlined call depth (default: 8)
  --sym-max-loop=N     Max loop unroll count (default: 3)
  --sym-entry=file     Only analyze this entry file with the symbolic engine
  --sym-no-includes    Do not inline include/require in the symbolic engine

Examples:
  popchain scan ./src
  popchain find ./chal.php --pop-cmd='id'
  cat chal.php | popchain find --stdin --pop-cmd='id'
  popchain find --url=https://example.com/chal.php --pop-cmd='id'
  popchain find ./src --class=Entry --sink=system --pop-cmd='id'
  popchain find ./src --sink=file_get_contents --pop-read-file=/flag
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
  --pop-cmd=值             命令执行类 sink 的写入值（默认 id）
  --pop-read-file=路径     文件读取 / 包含类 sink 的写入值（默认 /etc/passwd）
  --pop-write-file=路径    文件写入类 sink 的写入值（默认 /tmp/popchain.txt）
  --pop-delete-file=路径   文件删除类 sink 的写入值（默认 /tmp/popchain.txt）
  --pop-move-file=路径     文件移动 / 复制类 sink 的写入值（默认 /tmp/popchain.txt）
  --pop-chmod-file=路径    chmod 类 sink 的写入值（默认 /tmp/popchain.txt）
  --pop-ssrf-url=地址      SSRF 类 sink（simplexml_load_file / get_headers 等）的写入值（默认 http://127.0.0.1/）
  --pop-env-value=文本     putenv 类 sink 的写入值（默认 POPCHAIN=1）
  --pop-var-value=文本     parse_str 类 sink 的写入值（默认 popchain=1）
  --pop-mail-to=地址       mail 类 sink 的写入值（默认 a@b.test）
  --pop-serialized=数据    unserialize 类 sink 的写入值（默认 a:0:{}）
  --output=文件        把 payload 写入文件（JSON 模式写 JSON）
  --max-depth=8        链最大深度
  --max-chains=50      最多输出多少条链
  --include-vendor     连 vendor/ 目录一起扫描
  --json               以 JSON 输出
  --color              强制 ANSI 彩色输出（即使不是终端）
  --no-color           关闭彩色（NO_COLOR 环境变量同样生效）
  --type=all|deserialization|symbolic|dom-xss  要运行的检测类型（默认 all = deserialization + symbolic；dom-xss 需显式启用）
  --no-gadget-gate      目录扫描时即使没有受污点控制的 unserialize() 入口也保留 POP 链
  --list-types         列出可用检测类型后退出
  --obfuscate          字符串改用 S: 十六进制转义，绕过关键字过滤
  --encode=url|base64  payload 直接编码成可直接粘贴的形式：url 用于 ?data=，base64 便于文本传输
                       （--output 也写编码后的形式）
  --engine=none|external  引擎覆盖：none 关闭内置求解器，external 配合 --engine-cmd 使用
  --engine-cmd=命令    外部引擎命令：stdin 收 JSON 请求，stdout 回 JSON 响应
  --engine-timeout=秒  引擎超时时间（默认 10 秒）
  --sym-timeout=秒      符号执行时间上限（默认 30 秒）
  --sym-max-paths=N     最大路径数（默认 2000）
  --sym-max-states=N    每个执行点的最大状态数（默认 128）
  --sym-max-depth=N     函数内联最大深度（默认 8）
  --sym-max-loop=N      循环最大展开次数（默认 3）
  --sym-entry=文件      只分析指定入口文件
  --sym-no-includes     不内联 include/require

示例:
  popchain scan ./src
  popchain find ./chal.php --pop-cmd='id'
  cat chal.php | popchain find --stdin --pop-cmd='id'
  popchain find --url=https://example.com/chal.php --pop-cmd='id'
  popchain find ./src --class=Entry --sink=system --pop-cmd='id'
  popchain find ./src --sink=file_get_contents --pop-read-file=/flag
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

        $color = self::useColor($options);
        echo self::paint(self::t('scanning', ['path' => $label]), '1;36', $color), "\n";
        echo self::paint(self::t('classes', ['count' => count($classes)]), '90', $color), "\n\n";

        foreach ($rows as $row) {
            $kind = $row['trait'] ? ' (trait)' : ($row['interface'] ? ' (interface)' : ($row['enum'] ? ' (enum)' : ''));
            $parent = $row['parent'] !== null ? self::paint(' extends ' . $row['parent'], '90', $color) : '';
            $kindText = $kind !== '' ? self::paint($kind, '90', $color) : '';
            printf(
                "%s%s%s  %s
",
                self::paint((string) $row['class'], '1', $color),
                $parent,
                $kindText,
                self::paint(basename((string) $row['file']) . ':' . $row['line'], '90', $color),
            );
            if ($row['magic'] !== []) {
                echo '  ', self::paint(self::t('magic'), '2', $color), self::paint(implode(', ', $row['magic']), '36', $color), "
";
            }
            foreach ($row['sinks'] as $sink) {
                echo '  ', self::paint(self::t('sink'), '2', $color), self::paint($sink, '33', $color), "
";
            }
            echo "
";
        }

        foreach ($parser->warnings() as $warning) {
            echo '[warn] ', $warning, "\n";
        }

        return 0;
    }

    private static function find(string $path, string $label, array $options): int
    {
        $type = strtolower((string) self::option($options, 'type', 'all'));

        $engineCommand = self::option($options, 'engine-cmd');
        $engineName = strtolower((string) self::option($options, 'engine', ''));
        if ($engineName === '') {
            if (is_string($engineCommand) && $engineCommand !== '') {
                $engineName = 'external';
            } elseif (class_exists('PopChain\Symbolic\SymbolicEngine')) {
                $engineName = 'builtin';
            } else {
                $engineName = 'none';
            }
        }
        $engine = EngineRegistry::create($engineName, [
            'engine-cmd' => $engineCommand,
            'engine-timeout' => (float) self::option($options, 'engine-timeout', 10),
        ]);
        if ($engineName === 'external' && !$engine->isAvailable()) {
            fwrite(STDERR, '[!] ' . self::t('engine_unavailable', ['engine' => $engineName]) . "\n");
        }

        $detectors = $type === 'all'
            ? DetectorRegistry::createAll($engine)
            : [DetectorRegistry::create($type, $engine)];

        $knownDetector = false;
        foreach ($detectors as $detector) {
            if ($detector !== null) {
                $knownDetector = true;
            }
        }
        if (!$knownDetector) {
            fwrite(STDERR, '[!] ' . self::t('unknown_type', [
                'type' => $type,
                'types' => 'all, ' . implode(', ', DetectorRegistry::names()),
            ]) . "
");
            return 2;
        }

        $needsClasses = false;
        foreach ($detectors as $detector) {
            if ($detector !== null && $detector->name() === 'deserialization') {
                $needsClasses = true;
            }
        }

        $classes = [];
        $warnings = [];
        if ($needsClasses) {
            $parser = new ProjectParser((bool) self::option($options, 'include-vendor', false));
            $classes = $parser->parsePath($path);
            $warnings = $parser->warnings();
            if ($classes === [] && $type === 'deserialization') {
                fwrite(STDERR, '[!] ' . self::t('no_classes', ['path' => $label]) . "
");
                foreach ($warnings as $warning) {
                    fwrite(STDERR, '[warn] ' . $warning . "
");
                }
                return 2;
            }
        }

        $detectorOptions = [
            'path' => $path,
            'entries' => self::entries($options),
            'max_depth' => (int) self::option($options, 'max-depth', 8),
            'max_chains' => (int) self::option($options, 'max-chains', 50),
            'sym_timeout' => (float) self::option($options, 'sym-timeout', 30),
            'sym_max_paths' => (int) self::option($options, 'sym-max-paths', 2000),
            'sym_max_states' => (int) self::option($options, 'sym-max-states', 128),
            'sym_max_depth' => (int) self::option($options, 'sym-max-depth', 8),
            'sym_max_loop' => (int) self::option($options, 'sym-max-loop', 3),
            'sym_entry' => self::option($options, 'sym-entry', null),
            'sym_follow_includes' => !isset($options['sym-no-includes']),
            'include_vendor' => (bool) self::option($options, 'include-vendor', false),
        ];

        $findings = [];
        foreach ($detectors as $detector) {
            if ($detector === null) {
                continue;
            }
            foreach ($detector->detect($classes, $detectorOptions) as $finding) {
                $findings[] = $finding;
            }
            foreach ($detector->warnings() as $detectorWarning) {
                $warnings[] = $detectorWarning;
            }
        }

        $classFilter = self::option($options, 'class');
        $sinkFilter = self::option($options, 'sink');
        // 目录级 all 扫描：代码里根本没有（或明显不是外部输入进入的）unserialize() 时，
        // POP 链只是 gadget，不报；有候选入口但符号执行没确认时保留链并加备注。
        $gatePossible = $type === 'all'
            && is_dir($path)
            && !(bool) self::option($options, 'no-gadget-gate', false);
        $hasUnserializeCandidate = $gatePossible
            && self::hasUnserializeCandidate($path, (bool) self::option($options, 'include-vendor', false));
        $gateGadgets = $gatePossible && !$hasUnserializeCandidate;
        $builder = new PayloadBuilder($classes, (bool) self::option($options, 'obfuscate', false));
        $encode = strtolower((string) self::option($options, 'encode', ''));

        $hasPopChains = false;
        $hasTaintedUnserialize = false;
        foreach ($findings as $finding) {
            if ($finding->chain !== null && $finding->feasible) {
                $hasPopChains = true;
            }
            if ($finding->type === 'symbolic' && strtoupper($finding->category) === 'OBJECT_INJECTION') {
                $hasTaintedUnserialize = true;
            }
        }

        $records = [];
        $suppressedChains = 0;
        foreach ($findings as $finding) {
            if (!$finding->feasible) {
                fwrite(STDERR, '[!] ' . self::t('engine_infeasible', ['entry' => $finding->title]) . "\n");
                continue;
            }

            $chain = $finding->chain;
            if ($gateGadgets && $chain !== null && !$hasTaintedUnserialize) {
                $suppressedChains++;
                continue;
            }
            if ($chain !== null) {
                if (!self::chainMatchesFilters($chain, $classFilter, $sinkFilter)) {
                    continue;
                }

                $category = $chain->sink?->category;
                $payloadString = $builder->build($chain, self::valueFor($category, $options));
                $notes = $finding->notes;
                if (!$hasTaintedUnserialize && $gatePossible) {
                    $notes[] = self::t('gadget_unverified');
                }
                if (!in_array(strtolower($chain->entryMethod->name), self::DESERIALIZE_ENTRIES, true)) {
                    $notes[] = self::t('external_entry');
                }
                if ($chain->sink !== null && !$builder->sinkValueWritten) {
                    $notes[] = self::t('incomplete_payload');
                }
                if ($builder->wakeupBypassUsed) {
                    $notes[] = self::t('wakeup_bypass');
                }

                $records[] = [
                    'type' => 'deserialization',
                    'severity' => self::severityFor($category),
                    'category' => $category,
                    'title' => implode(' -> ', $chain->nodes()),
                    'file' => $chain->entryClass->file,
                    'line' => $chain->entryClass->line,
                    'chain' => $chain->nodes(),
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
                        'display' => sprintf(
                            '%s() %s #%d <- %s',
                            $chain->sink->function,
                            self::t('arg'),
                            $chain->sink->position,
                            $chain->sink->arg->describe(),
                        ),
                    ],
                    'taint' => [],
                    'path' => [],
                    'payload' => $payloadString,
                    'payload_encoded' => self::encodePayload($payloadString, $encode),
                    'notes' => $notes,
                    'snippet' => '',
                    'literal_sink' => $chain->literalSink,
                ];
                continue;
            }

            if (!self::symbolicMatchesFilters($finding, $sinkFilter)) {
                continue;
            }

            $symbolicNotes = $finding->notes;
            if (!$hasPopChains && strtoupper((string) $finding->category) === 'OBJECT_INJECTION') {
                $symbolicNotes[] = self::t('no_pop_chain');
            }

            $records[] = [
                'type' => $finding->type,
                'severity' => $finding->severity,
                'category' => $finding->category,
                'title' => $finding->title,
                'file' => $finding->file,
                'line' => $finding->line,
                'chain' => [],
                'edges' => [],
                'sink' => $finding->sink === '' ? null : ['display' => $finding->sink],
                'taint' => $finding->taint,
                'path' => $finding->path,
                'payload' => $finding->payload,
                'payload_encoded' => self::encodePayload($finding->payload, $encode),
                'notes' => $symbolicNotes,
                'snippet' => $finding->snippet,
            ];
        }

        if ($suppressedChains > 0) {
            $warnings[] = self::t('gadgets_suppressed', ['count' => $suppressedChains]);
        }

        $jsonDocument = [
            'tool' => 'php_pop_framework',
            'version' => self::VERSION,
            'path' => $label,
            'findings' => $records,
            'warnings' => $warnings,
        ];

        $outputFile = self::option($options, 'output');
        if (is_string($outputFile) && $outputFile !== '') {
            $content = self::option($options, 'json', false)
                ? json_encode($jsonDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : implode("\n", array_values(array_filter(
                    array_map(static fn (array $record): string => (string) ($record['payload_encoded'] ?? ''), $records),
                    static fn (string $line): bool => $line !== '',
                )));
            if (@file_put_contents($outputFile, $content . "\n") === false) {
                fwrite(STDERR, '[!] ' . self::t('output_failed', ['file' => $outputFile]) . "\n");
                return 2;
            }
            fwrite(STDERR, self::t('payload_written', ['file' => $outputFile]) . "\n");
        }

        if (self::option($options, 'json', false)) {
            echo json_encode($jsonDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            return $records === [] ? 1 : 0;
        }

        $color = self::useColor($options);
        echo self::paint(self::t('scanning', ['path' => $label]), '1;36', $color), "\n";
        if (!in_array($type, ['symbolic', 'dom-xss'], true)) {
            echo self::paint(self::t('classes', ['count' => count($classes)]), '90', $color), "
";
            echo self::paint(self::t('entries'), '90', $color), self::paint(implode(', ', self::entries($options)), '36', $color), "
";
        }
        echo "\n";

        if ($records === []) {
            foreach ($warnings as $warning) {
                echo '[warn] ', $warning, "\n";
            }
            if (!in_array($type, ['symbolic', 'dom-xss'], true)) {
                echo self::t('no_chains'), "\n";
                echo self::t('try_entries'), "\n";
                echo self::t('try_depth'), "\n";
                echo self::t('try_class'), "\n";
            } else {
                echo self::t('no_findings'), "\n";
            }
            return 1;
        }

        foreach ($records as $index => $record) {
            $severity = strtoupper((string) $record['severity']);
            $severityColor = match (strtolower($severity)) {
                'critical' => '1;31',
                'high' => '1;33',
                'medium' => '33',
                'low' => '36',
                default => '1;37',
            };

            echo sprintf(
                "[%d] %s %s  %s\n",
                $index + 1,
                self::paint('[' . $severity . ']', $severityColor, $color),
                self::paint((string) $record['title'], '1', $color),
                self::paint('(' . $record['type'] . '/' . (string) ($record['category'] ?? '') . ')', '90', $color),
            );

            if ((string) ($record['file'] ?? '') !== '') {
                echo '    ', self::paint($record['file'] . ':' . (int) ($record['line'] ?? 0), '90', $color), "\n";
            }

            foreach ($record['edges'] as $edge) {
                printf(
                    "    %s %-14s \$this->%-14s %s\n",
                    self::paint(self::t('via'), '2', $color),
                    self::paint((string) $edge['via'], '36', $color),
                    $edge['property'],
                    self::paint((string) $edge['line'], '90', $color),
                );
            }

            if (($record['taint'] ?? []) !== []) {
                echo '    ', self::paint('taint:', '2', $color), ' ', self::paint(implode(', ', $record['taint']), '35', $color), "\n";
            }
            if (($record['path'] ?? []) !== []) {
                echo '    ', self::paint('path:', '2', $color), ' ', self::paint(implode(' && ', $record['path']), '35', $color), "\n";
            }
            if ($record['sink'] !== null && isset($record['sink']['display'])) {
                echo '    ', self::paint('sink:', '2', $color), ' ', self::paint((string) $record['sink']['display'], '31', $color), "\n";
            }
            foreach ($record['notes'] as $note) {
                echo '    ', self::paint((string) $note, '33', $color), "\n";
            }
            if ((string) ($record['payload_encoded'] ?? '') !== '') {
                echo '    ', self::paint('payload:', '2', $color), ' ', self::paint(self::displayable($record['payload_encoded']), '36', $color), "\n";
            }
            if ((string) ($record['snippet'] ?? '') !== '') {
                echo '    ', self::paint('|', '90', $color), ' ', self::paint(trim((string) $record['snippet']), '90', $color), "\n";
            }
            echo "\n";
        }

        foreach ($warnings as $warning) {
            echo '[warn] ', $warning, "\n";
        }

        return 0;
    }

    private static function chainMatchesFilters(Chain $chain, mixed $classFilter, mixed $sinkFilter): bool
    {
        if (is_string($classFilter) && $classFilter !== '') {
            $wanted = strtolower(ltrim($classFilter, '\\'));
            $name = strtolower($chain->entryClass->name);
            $short = strtolower($chain->entryClass->shortName());
            if ($name !== $wanted && $short !== $wanted) {
                return false;
            }
        }

        if (is_string($sinkFilter) && $sinkFilter !== '') {
            if ($chain->sink === null || strcasecmp($chain->sink->function, $sinkFilter) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function symbolicMatchesFilters(Finding $finding, mixed $sinkFilter): bool
    {
        if (!is_string($sinkFilter) || $sinkFilter === '') {
            return true;
        }

        $function = strtolower(trim((string) strtok($finding->sink, '(')));
        return $function === strtolower($sinkFilter)
            || str_contains(strtolower($finding->sink), strtolower($sinkFilter));
    }

    private static function severityFor(?string $category): string
    {
        return match ($category) {
            'rce', 'deserialize' => 'critical',
            'file_write', 'file_delete', 'file_move', 'file_copy', 'file_perms', 'include', 'ssrf' => 'high',
            'file_read', 'env', 'var', 'mail' => 'medium',
            default => 'high',
        };
    }

    private static function useColor(array $options): bool
    {
        if (isset($options['no-color']) || getenv('NO_COLOR') !== false) {
            return false;
        }
        if (isset($options['color'])) {
            return true;
        }
        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private static function paint(string $text, string $code, bool $color): string
    {
        return $color ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
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
     * 按 sink 分类取 payload 值：--pop-* 专用选项，否则用分类默认值。
     *
     * @param array<string, mixed> $options
     */
    private static function valueFor(?string $category, array $options): string
    {
        $optionName = $category !== null ? (self::CATEGORY_OPTIONS[$category] ?? null) : null;

        if ($optionName !== null) {
            $specific = self::option($options, $optionName, null);
            if (is_string($specific) && $specific !== '') {
                return $specific;
            }
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

    private static function encodePayload(string $payload, string $encode): string
    {
        return match ($encode) {
            'url' => rawurlencode($payload),
            'base64' => base64_encode($payload),
            default => $payload,
        };
    }

    private static function displayable(string $payload): string
    {
        return str_replace("\0", '\0', $payload);
    }

    /** 目录里是否存在明显由外部输入进入的 unserialize() 候选。 */
    private static function hasUnserializeCandidate(string $path, bool $includeVendor): bool
    {
        $sourceBody = '\\$_(?:GET|POST|COOKIE|REQUEST|FILES|SERVER)\\b'
            . '|php:\\/\\/input'
            . '|getallheaders\\s*\\('
            . '|file_get_contents\\s*\\(\\s*[\'"]php:\\/\\/input'
            . '|->(?:input|get|cookie|param|post|query|header)\\s*\\(';
        $sourceRe = '/' . $sourceBody . '/i';

        try {
            $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator(
                $directory,
                static fn (\SplFileInfo $current): bool => !in_array(
                    $current->getFilename(),
                    ['.git', '.idea', '.svn'],
                    true,
                ),
            );
            $iterator = new \RecursiveIteratorIterator($filter);

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $relative = str_replace('\\', '/', $file->getPathname());
                if (!$includeVendor && (str_contains($relative, '/vendor/') || str_contains($relative, '/node_modules/'))) {
                    continue;
                }
                $content = @file_get_contents($file->getPathname());
                if (!is_string($content) || !str_contains($content, 'unserialize')) {
                    continue;
                }
                $tokens = token_get_all($content);
                $count = count($tokens);
                for ($index = 0; $index < $count; $index++) {
                    $token = $tokens[$index];
                    if (!is_array($token)
                        || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                        || strtolower(ltrim($token[1], '\\')) !== 'unserialize') {
                        continue;
                    }
                    $open = $index + 1;
                    while ($open < $count && is_array($tokens[$open])
                        && in_array($tokens[$open][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $open++;
                    }
                    if (($tokens[$open] ?? null) !== '(') {
                        continue;
                    }
                    $depth = 0;
                    $argument = '';
                    for ($cursor = $open; $cursor < $count; $cursor++) {
                        $current = $tokens[$cursor];
                        if ($current === '(') {
                            $depth++;
                            if ($depth === 1) {
                                continue;
                            }
                        } elseif ($current === ')') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        }
                        if (is_array($current)) {
                            if (in_array($current[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                                continue;
                            }
                            $argument .= $current[1];
                        } else {
                            $argument .= $current;
                        }
                    }
                    if (preg_match($sourceRe, $argument) === 1) {
                        return true;
                    }
                    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $argument, $variables) < 1) {
                        continue;
                    }
                    foreach (array_unique($variables[1]) as $variable) {
                        if (preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*[^;]{0,300}(?:' . $sourceBody . ')/i', $content) === 1) {
                            return true;
                        }
                    }
                    // 实参无法判定为纯本地常量时，保守地视为“未确认候选”，避免误抑制
                    if (preg_match('/\$|[A-Za-z_][A-Za-z0-9_]*\s*\(/', $argument) === 1) {
                        return true;
                    }
                }
            }
        } catch (\UnexpectedValueException) {
            // 读不了的目录按“有候选”处理，宁可保留链也不误抑制
            return true;
        }

        return false;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
