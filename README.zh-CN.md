# php_pop_framework

English: [README.md](README.md)

php_pop_framework 是一个 PHP 反序列化 POP 链自动生成器，面向 CTF / 授权代码审计。命令行入口是 `popchain`。

仓库内同时合并了 **SymPHP** 符号执行 / 污点分析引擎，作为第二种检测类型，覆盖 XSS、SQL 注入、命令注入、文件包含、SSRF、XXE 等。两个检测器默认一起跑（`--type=all`）；只想跑一种就用 `--type=deserialization` 或 `--type=symbolic`。另有启发式 `--type=dom-xss`：扫描 JS/内联模板里的动态 `innerHTML` / `document.write` sink（DOM/存储型 XSS 候选），因为精度换覆盖面，需要显式启用。

输入一份 PHP 源码（单个文件、目录、stdin 或 URL），工具会：

1. 解析所有类、属性、方法与调用点；
2. 从魔术方法入口出发，沿可控属性搜索；
3. 命中危险 sink（`system`、`eval`、`include`、`file_put_contents` 等）；
4. 生成可直接提交的序列化 payload，并打印完整链路径。

作者：**guaidao2 & coolmoon** · 协议：[MIT](LICENSE)

## 环境要求

- PHP >= 8.1（CLI 即可）
- [nikic/php-parser](https://github.com/nikic/PHP-Parser) 5.x

## 安装依赖

任选一种：

```bash
# Composer，推荐：composer.json 固定了解析器版本并生成 vendor/autoload.php
composer install

# Kali / Debian
sudo apt install php-parser

# 离线：下载 php-parser 解压到 vendor/PhpParser/，bootstrap 会自动识别
```

`src/bootstrap.php` 会按以下顺序自动查找：

1. `vendor/PhpParser/autoload.php`（项目内 vendor，离线时手动放置）
2. `vendor/autoload.php`（Composer）
3. `/usr/share/php/PhpParser/autoload.php`（apt 安装）

找不到时会打印安装指引并退出。

## 统一入口

项目根目录的 `popchain` 是唯一入口（工具本身叫 php_pop_framework）；`bin/popchain` 是兼容别名，两者行为完全一致：

```bash
./popchain version
./popchain help
# 或者
php popchain find ./src --pop-cmd='id'
```

CLI 输出默认英文，加 `--lang=zh`（或 `--lang zh`）切换中文。

## 快速开始

### 本地单文件

从云端题目复制源码到本地后，直接指定文件：

```bash
./popchain scan ./chal.php
./popchain find ./chal.php --pop-cmd='id'
```

### 标准输入（不落盘）

```bash
cat chal.php | ./popchain find --stdin --pop-cmd='id'
# 或
./popchain find --stdin --pop-cmd='id' < chal.php
```

### 从 URL 拉取

```bash
./popchain find --url=https://example.com/chal.php --pop-cmd='id'
```

URL 可以直接是纯 PHP 源码；如果是 `highlight_file` / `highlight_string` 渲染出来的源码页（CTF 题里很常见），工具会去掉 HTML 标签、反转义后还原源码再分析。其它渲染后的 HTML（页面已经执行过、需要登录、API 返回）无法还原，工具会提示该 URL 不是源码。

### 目录

```bash
./popchain find ./src --sink=system --pop-cmd='id'
./popchain find ./src --sink=file_get_contents --pop-read-file=/flag
./popchain find ./src --type=symbolic   # XSS / SQLi / RCE / LFI 污点分析
./popchain find ./src --entry=all --output=payloads.txt
```

`find` 输出示例：

```
[1] [CRITICAL] Entry::__destruct -> Runner::__invoke -> Sink::__invoke  (deserialization/rce)
    examples/demo.php:3
    via call_property  $this->callback       Entry:8
    via callable       $this->fn             Runner:17
    sink: system() arg #0 <- $cmd
    engine: UNKNOWN (1 constraint, 0 skipped)
    payload: O:5:"Entry":1:{s:8:"callback";O:6:"Runner":2:{s:2:"fn";O:4:"Sink":0:{}s:3:"arg";s:2:"id";}}
```

`find --type=symbolic` 输出示例（同一套格式，换的是检测器）：

```
[1] [CRITICAL] OS Command Injection  (symbolic/RCE)
    examples/symbolic/vuln/rce.php:9
    taint: $_POST['cmd']
    path: (strlen($_POST['cmd']) > 0)
    sink: system()
    payload: id
    | system($cmd);
```

## 架构

这个仓库是按框架组织的，不是单用途扫描器：

- `src/Parser` 把 PHP 源码解析成类/方法元数据、守卫条件和语句级 CFG（`src/Ir`）；
- `src/Detector` 放检测器插件。内置两个：`deserialization`（POP 链）和 `symbolic`
  （路径敏感污点分析，底层是合并进来的 SymPHP）。新增漏洞类型实现 `DetectorInterface`
  并在 `DetectorRegistry` 注册，CLI 的 `--type` / `--list-types` 就会自动带上；
- `src/Analysis` 提供各检测器共用的链搜索、sink 匹配和守卫收集；
- `src/Payload` 负责把链变成序列化 payload；
- `src/Symbolic` 是原生合并的 **SymPHP** 引擎（命名空间 `PopChain\Symbolic`，与项目共用
  自动加载）：解析器、符号解释器、约束求解器、污点跟踪和报告器。通过 CLI 的
  `--type=symbolic` 使用，预算参数以 `--sym-*` 暴露。
- 同一套求解器也作为反序列化检测器的默认进程内引擎（`PopChain\Symbolic\SymbolicEngine`）：
  链上收集到的守卫条件会翻译成符号表达式做可满足性检查，UNSAT 的链直接丢弃，
  UNKNOWN 保持原结果。用 `--engine=none` 可以关掉。`src/Engine` 另支持外部进程引擎。
  详见 [docs/symphp.md](docs/symphp.md) 和
  [docs/symbolic-engine-protocol.md](docs/symbolic-engine-protocol.md)。

```
CLI（--type）
  └─ 检测器注册表
       ├─ 反序列化检测器
       │    ├─ ChainFinder + 守卫约束
       │    ├─ 引擎适配层（默认内置求解器）
       │    └─ PayloadBuilder
       └─ 符号执行检测器
            └─ SymPHP 引擎（符号执行 + 污点分析）
```

## 命令与选项

```
popchain scan <路径|文件> [选项]
popchain find <路径|文件> [选项]
popchain find --stdin [选项]
popchain find --url=<地址> [选项]
popchain version
```

| 选项 | 说明 |
|---|---|
| `--lang=en\|zh` | 输出语言，默认 `en`；`--lang=zh` 切换中文 |
| `--entry=a,b` | 入口魔术方法，默认 `__destruct,__wakeup,__unserialize`；`--entry=all` 启用全部魔术方法 |
| `--class=Entry` | 只保留指定类（短名或完整命名空间）作为入口的链 |
| `--sink=system` | 只保留命中指定 sink 的结果（两个检测器都生效） |
| `--pop-cmd=值` | 命令执行类 sink 的写入值（默认 `id`） |
| `--pop-read-file=路径` | 文件读取 / `include` 类 sink 的写入值（默认 `/etc/passwd`） |
| `--pop-write-file=路径` | 文件写入类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--pop-delete-file=路径` | 文件删除类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--pop-move-file=路径` | 文件移动 / 复制类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--pop-chmod-file=路径` | `chmod` 类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--pop-ssrf-url=地址` | SSRF 类 sink（`simplexml_load_file` / `get_headers` 等）的写入值（默认 `http://127.0.0.1/`） |
| `--pop-env-value=文本` | `putenv` 类 sink 的写入值（默认 `POPCHAIN=1`） |
| `--pop-var-value=文本` | `parse_str` 类 sink 的写入值（默认 `popchain=1`） |
| `--pop-mail-to=地址` | `mail` 类 sink 的写入值（默认 `a@b.test`） |
| `--pop-serialized=数据` | `unserialize` 类 sink 的写入值（默认 `a:0:{}`） |
| `--output=文件` | 把 payload 写入文件；JSON 模式写 JSON |
| `--max-depth=8` | 链最大深度 |
| `--max-chains=50` | 最多输出多少条链 |
| `--include-vendor` | 连 `vendor/` 目录一起扫描 |
| `--json` | 以 JSON 输出 |
| `--color` | 强制 ANSI 彩色输出（即使不是终端） |
| `--no-color` | 关闭彩色；`NO_COLOR` 环境变量同样生效 |
| `--obfuscate` | 字符串改用 `S:` 十六进制转义，关键字不会被过滤器看到 |
| `--encode=url\|base64` | payload 直接编码，方便复制粘贴：`url` 用于 `?data=`，`base64` 便于文本传输；`--output` 也写编码后的形式 |
| `--type=all\|deserialization\|symbolic\|dom-xss` | 检测类型：`all`（默认 = deserialization + symbolic），`dom-xss` 是启发式、需显式启用；见 `--list-types` |
| `--no-gadget-gate` | 目录 all 扫描在找不到受污点控制的 `unserialize()` 入口时会抑制 POP 链，此开关保留链 |
| `--list-types` | 列出可用检测类型后退出 |
| `--engine=none\|external` | 引擎覆盖：内置进程内求解器默认启用，`none` 关闭，`external` 配合 `--engine-cmd` 使用 |
| `--engine-cmd=命令` | 外部引擎命令：stdin 收 JSON 请求，stdout 回 JSON 响应 |
| `--engine-timeout=秒` | 引擎超时时间（默认 10 秒） |
| `--sym-timeout=秒` | 符号执行时间上限（默认 30 秒） |
| `--sym-max-paths=N` | 最大路径数（默认 2000） |
| `--sym-max-states=N` | 每个执行点的最大状态数（默认 128） |
| `--sym-max-depth=N` | 函数内联最大深度（默认 8） |
| `--sym-max-loop=N` | 循环最大展开次数（默认 3） |
| `--sym-entry=文件` | 只分析指定入口文件 |
| `--sym-no-includes` | 不内联 `include` / `require` |

## 工作原理

```
PHP 源码（文件 / stdin / URL）
  └─ ProjectParser（nikic/php-parser + NameResolver）
       └─ IR：ClassInfo / PropertyInfo / MethodInfo / CallSite / ArgInfo
            └─ ChainFinder（DFS）
                 ├─ ($this->p)(...)                 → 任意类 __invoke
                 ├─ call_user_func($this->p, ..)    → 任意类 __invoke
                 ├─ call_user_func([$this->p, 'm']) → 方法 m / __call
                 ├─ call_user_func([$this->p, "do".ucfirst($this->mode)], ..) → 动态方法解析
                 ├─ $this->p->m(...)                → 任意类方法 m / __call
                 ├─ self::m(...) / Foo::m(...)      → 静态方法
                 ├─ $this->p->name                  → 任意类 __get / __isset
                 └─ $this->p 在字符串上下文          → 任意类 __toString
                      └─ SinkScanner 命中危险函数
                           └─ PayloadBuilder → 手写序列化器 → payload
```

每次跳转前，解析器会先沿局部污点解析一遍，所以绕了路的参数也能回溯到属性：

```php
$x = $this->cmd;                 // 局部变量拷贝
$this->target = $this->source;   // 属性到属性
system('ping -c1 ' . $x);        // 字符串拼接保留可控部分
```

payload 由 `src/Payload/Serializer.php` 直接按 PHP 序列化格式拼接，**不会加载或执行目标类**。含 private/protected 属性的 payload 带 NUL 字节，shell 和终端通常会把它吃掉：别直接复制输出的那一行，用 `--output` 取文件，或者加 `--encode=url` / `--encode=base64`。属性键遵循 PHP 约定：

- public：`name`
- protected：`\0*\0name`
- private：`\0DeclaringClass\0name`

## 支持的能力

- 输入：单文件、目录、stdin、URL
- 类继承（含从抽象父类继承来的入口方法）、接口、构造函数属性提升
- trait 压平进使用它的类，嵌套 trait 一并处理；trait 属性归属使用类，private 键因此能正确序列化
- 魔术方法入口：`__destruct`、`__wakeup`、`__unserialize`，以及可配置的 `__toString` / `__invoke` / `__call` / `__get` / `__isset` / `__clone`
- 链边：属性可调用、可调用数组（`[$this->obj, 'method']`）、方法调用（含 `__call` 兜底）、静态调用（`self::` / `static::` / `parent::` / `Foo::`）、触发 `__get` / `__isset` 的属性读取、字符串上下文触发 `__toString`
- 字符串上下文覆盖 `echo` / `print`、拼接、字符串强转、`die()` / `exit()`、插值（`"{$this->obj}"`）、字符串内置函数的实参（`strlen`、`str_replace`、`sprintf`、`strval` 等），以及 `file_get_contents` 这类 sink 函数的字符串参数
- 局部污点传播：变量拷贝、把局部别名当方法接收者、属性到属性赋值、字符串拼接，sink 参数因此仍可回溯
- 回调实参经数组传递的情形（`call_user_func_array`、`array_map`、`array_filter`、`array_walk`、`array_reduce`）按数组元素映射，payload 写数组值而不是标量
- `usort` / `uasort` / `uksort` 比较器：前两个形参分别对应数组的前两个元素，元素值保持可控
- 变量函数：`$fn = $this->cb; $fn(...)`、`$fn = [$this->obj, 'method']; $fn(...)`、`$fn = 'system'; $fn(...)` 都能解析成真实调用点
- `__call` / `__callStatic`：`$args[0]` 会映射回调用点对应的实参
- `__unserialize(array $data)`：支持 `$data['a']['b']` 这种嵌套读取，不只是一层
- trait 冲突适配：`insteadof` 选中覆盖方，`as` 支持别名与可见性修改
- `__wakeup` 对属性的字面量重置会在任意嵌套深度上被识别；依赖这类属性的链，生成的 payload 会故意把声明属性数写得比类实际属性多、body 少给，让 `unserialize()` 在该对象处失败：`__wakeup` 被跳过、已构造对象的析构函数照跑（CVE-2016-7124 式绕过），CLI 会打印提示。定义了 `__unserialize` 的类按 PHP 语义单独处理（此时不会调用 `__wakeup`）
- 参数回溯：sink 参数来自方法参数时，沿调用链反推最终要赋值的属性；调用点传的是常量时按不可控处理，不会产出没有 sink 值的链
- 守卫条件：沿链收集"要走通被守卫的代码，属性必须满足什么"，并写进 payload。覆盖：与字面量的相等 / 不等 / 大小比较（`$this->is_admin === true`、`$this->token === 'admin_token'`、`$this->ttl <= 0`）、`isset` / `empty`、类型判断（`is_array`、`is_string`、`is_int`、`is_float`、`is_bool`、`is_null`、`is_numeric`、`is_iterable`）、真假判断（`if ($this->flag)` / `if (!$this->flag)`）、早退守卫（`if (...) { return; }`）以及 else 分支
- `__unserialize(array $data)` 入口：`$data['cmd']` 视为攻击者可控数据，赋给属性后进 sink、或直接作为 sink 实参都能出链
- 动态分发：`call_user_func([$this->handler, "do" . ucfirst($this->mode)], $this->param)` 以及经局部变量转存的同类写法，会按字面前缀匹配公开方法解析目标方法，并自动把拼方法名的属性写成对应值（`mode = execute`）；`call_user_func` 的实参转发用于把目标方法形参映射回属性
- 方法形参约束：`is_array($args)`、`count($args) === 2`、`$args[0] == 'admin'`、`is_numeric($args[1])`、`intval($args[1]) > 1000` 会转换成转发进该形参的属性的数组值（`param = ['admin', 1001]`）
- 常量 sink 实参：sink 参数是写死的字面量时（例如 `system("cat /flag")`），链仍会作为可达性链输出，并标注实参本身不可控；payload 只需要走到 sink
- sink 分类：RCE、文件读写/删除/移动、文件包含、反序列化、可调用、环境、变量注入等
- 统一输出：反序列化链和符号执行结果共用一种格式，文本报告是 `[N] [严重度] 标题  (类型/分类)`，后跟位置、sink、污点/路径、备注与 payload；JSON 统一放在 `findings` 数组里，字段包括 `type`、`severity`、`category`、`title`、`file`、`line`、`sink`、`taint`、`path`、`payload`、`notes`，以及 `chain`/`edges` 这类类型专属字段
- payload 输出：文本报告与 JSON（带工具名与版本）；payload 可落盘，终端里 NUL 字节转义显示，`--output` 仍写原始字节
- 入口语义：从 `__invoke` / `__toString` / `__get` 等非反序列化魔术方法起步的链会被标注"需要外部触发"，避免把必须被调用才生效的 payload 当成独立 gadget
- CLI 校验：路径与数值选项校验，退出码清晰（0 找到链、1 未找到链、2 输入不合法）

## 已知限制

静态分析不可能覆盖 PHP 的全部动态特性。当前限制与启发式边界：

- `--type=dom-xss` 是启发式：不做完整 JS 数据流，只标动态 `innerHTML` / `document.write`，命中当线索而不是已确认的存储型 XSS
- 目录 all 扫描在找不到受污点控制的 `unserialize()` 入口时会抑制 POP 链；要看纯 gadget 链用 `--type=deserialization` 或 `--no-gadget-gate`

- 反射、`eval` 后生成的动态代码、运行时才确定的 `include` 路径
- 异常路径与循环次数不建模
- `preg_replace_callback`：回调拿到的是匹配数组而不是普通元素，不建立形参映射
- sink 参数涉及多个不同属性时，只把第一个可控属性写入 payload
- 靠函数返回值、算术、正则结果或多维数组下标算出来的守卫条件不建模；求解器覆盖字面量 / 类型比较，其余情况报 `unknown`
- 动态方法名按公开方法的字面前缀匹配解析；前缀撞车（大量方法同前缀）会多出候选链，需要人工确认；没有静态前缀的方法名不解析
- 常量 sink 实参只给出可达性链，硬编码操作是否真正有害取决于目标环境
- 方法形参约束只覆盖 `is_array` / `count` / 元素比较 / `intval` 比较
- 符号执行受路径/状态预算限制；大项目触顶时 CLI 会打印截断告警（加大 `--sym-max-paths` / `--sym-max-states` 可继续深入）
- 识别 `realpath()` 包含性守卫：`$real = realpath($path); $base = realpath('/固定目录'); if ($real === false || $base === false || strpos($real . '/', $base . '/') !== 0) exit;` 会抑制被限制在目录内的文件读取；弱前缀（根目录侧没补分隔符、base 为 `/`、base 是变量、方向写反、守卫不终止）仍会报告；`$_SERVER['DOCUMENT_ROOT']` 可作为服务端可控的 base
- 上传分析：`move_uploaded_file` 的目标名来自 `$_FILES`、而代码没有扩展名校验（字面量列表、`.php` 后缀比较或扩展名正则）时，报 `Unrestricted File Upload`；有扩展名黑/白名单或固定安全后缀（`.jpg`）则不报。只校验客户端可控的 MIME（`$_FILES[...]['type']`）不算扩展名校验。`pathinfo(..., PATHINFO_EXTENSION)` 和白名单 `preg_replace('/[^...]/', '_', ...)` 会清除路径穿越污点
- 代码通过 `header()` 声明 `Content-Type: application/json` 时，经 `json_encode()` 包装的输出不再按 XSS 报告
- 现代 PHP 的 `header()` / `setcookie()` 会拒绝含 CRLF 的值，响应头注入只对 `mail()` 报告；`realpath()` 的结果按本地路径处理，不再当作 SSRF 源；`str_replace` 同时删除两种路径分隔符后才清除路径穿越污点
- 魔术方法依赖运行时状态（已打开的资源句柄、活动的数据库连接、已加载扩展）的 gadget，仅靠属性注入无法复现
- 匿名类无法被 `unserialize` 实例化，因此有意跳过而不是建模

链是否真的可用，最终取决于目标环境的类定义与版本。建议在本地复现环境验证后再使用。

## 项目结构

```
php_pop_framework/
├── popchain                  # 统一入口
├── bin/popchain              # 兼容入口
├── src/
│   ├── bootstrap.php         # 依赖探测
│   ├── Cli/Application.php   # CLI 实现
│   ├── Parser/               # AST → 模型 + CFG + 守卫约束
│   ├── Ir/                   # 语句级控制流图
│   ├── Model/                # 类/方法/属性/调用点/链/边
│   ├── Detector/             # 检测器插件（反序列化、符号执行）
│   ├── Engine/               # 引擎适配层（内置求解器默认启用）
│   ├── Analysis/             # sink 识别 + 链搜索
│   ├── Symbolic/             # 合并的 SymPHP 引擎（PopChain\Symbolic 命名空间）
│   └── Payload/              # 对象图 → 手写序列化
├── examples/demo.php         # 示例漏洞代码
├── examples/symbolic/vuln/   # SymPHP 示例漏洞代码
├── tests/run.php             # 测试套件
├── tests/symbolic/           # SymPHP 引擎测试（合并）
├── tests/fixtures/           # 解析/链搜索用例
├── docs/symphp.md            # 合并的 SymPHP 引擎文档
├── docs/symbolic-engine-protocol.md  # 可插拔引擎的通信协议
├── composer.json             # 依赖与自动加载定义
├── README.zh-CN.md           # 中文文档
├── LICENSE                   # MIT
└── README.md
```

## 测试

```bash
php tests/run.php           # php_pop_framework 测试
php tests/symbolic/run.php  # 合并的 SymPHP 引擎测试
```

测试覆盖解析层、链搜索、payload 构建和 CLI。其中有几条用例会把 payload 真正反序列化、确认 sink 确实被触发——链到底能不能用，只有真跑一遍才知道。此外还检查 trait、继承、局部污点、`__get` / `__isset` 边、命名空间、private/protected 序列化键，以及 CLI 的各条输入路径。

## 安全声明

本工具仅用于**授权的 CTF 竞赛、教学和自有系统审计**。生成的 payload 会真实触发目标代码中的危险函数，请勿用于未授权的系统。

## License

[MIT](LICENSE) © 2026 guaidao2 & coolmoon
