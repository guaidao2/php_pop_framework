# php_pop_framework

English: [README.md](README.md)

php_pop_framework 是一个 PHP 反序列化 POP 链自动生成器，面向 CTF / 授权代码审计。命令行入口是 `popchain`。

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
php popchain find ./src --cmd='id'
```

CLI 输出默认英文，加 `--lang=zh`（或 `--lang zh`）切换中文。

## 快速开始

### 本地单文件

从云端题目复制源码到本地后，直接指定文件：

```bash
./popchain scan ./chal.php
./popchain find ./chal.php --cmd='id'
```

### 标准输入（不落盘）

```bash
cat chal.php | ./popchain find --stdin --cmd='id'
# 或
./popchain find --stdin --cmd='id' < chal.php
```

### 从 URL 拉取

```bash
./popchain find --url=https://example.com/chal.php --cmd='id'
```

注意：URL 必须是**纯 PHP 源码**。如果页面是执行后的 HTML、需要登录，或由 PHP 服务器执行过，拉到的不是源码，工具会解析不到类。

### 目录

```bash
./popchain find ./src --sink=system --cmd='id'
./popchain find ./src --sink=file_get_contents --read-file=/flag
./popchain find ./src --entry=all --output=payloads.txt
```

`find` 输出示例：

```
[1] Entry::__destruct → Runner::__invoke → Sink::__invoke
    via call_property  $this->callback       Entry:8
    via callable       $this->fn             Runner:17
    sink rce      system() 参数 #0 ← $cmd
    payload: O:5:"Entry":1:{s:8:"callback";O:6:"Runner":2:{...}}
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
| `--sink=system` | 只保留指定 sink 函数的链 |
| `--cmd=值` | 命令执行类 sink 的写入值（默认 `id`） |
| `--read-file=路径` | 文件读取 / `include` 类 sink 的写入值（默认 `/etc/passwd`） |
| `--write-file=路径` | 文件写入类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--delete-file=路径` | 文件删除类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--move-file=路径` | 文件移动 / 复制类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--chmod-file=路径` | `chmod` 类 sink 的写入值（默认 `/tmp/popchain.txt`） |
| `--ssrf-url=地址` | SSRF 类 sink（`simplexml_load_file` / `get_headers` 等）的写入值（默认 `http://127.0.0.1/`） |
| `--env-value=文本` | `putenv` 类 sink 的写入值（默认 `POPCHAIN=1`） |
| `--var-value=文本` | `parse_str` 类 sink 的写入值（默认 `popchain=1`） |
| `--mail-to=地址` | `mail` 类 sink 的写入值（默认 `a@b.test`） |
| `--serialized=数据` | `unserialize` 类 sink 的写入值（默认 `a:0:{}`） |
| `--output=文件` | 把 payload 写入文件；JSON 模式写 JSON |
| `--max-depth=8` | 链最大深度 |
| `--max-chains=50` | 最多输出多少条链 |
| `--include-vendor` | 连 `vendor/` 目录一起扫描 |
| `--json` | 以 JSON 输出 |
| `--obfuscate` | 字符串改用 `S:` 十六进制转义，关键字不会被过滤器看到 |

## 工作原理

```
PHP 源码（文件 / stdin / URL）
  └─ ProjectParser（nikic/php-parser + NameResolver）
       └─ IR：ClassInfo / PropertyInfo / MethodInfo / CallSite / ArgInfo
            └─ ChainFinder（DFS）
                 ├─ ($this->p)(...)                 → 任意类 __invoke
                 ├─ call_user_func($this->p, ..)    → 任意类 __invoke
                 ├─ call_user_func([$this->p, 'm']) → 方法 m / __call
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

payload 由 `src/Payload/Serializer.php` 直接按 PHP 序列化格式拼接，**不会加载或执行目标类**。属性键遵循 PHP 约定：

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
- `__unserialize(array $data)` 入口：`$data['cmd']` 视为攻击者可控数据，赋给属性后进 sink、或直接作为 sink 实参都能出链
- sink 分类：RCE、文件读写/删除/移动、文件包含、反序列化、可调用、环境、变量注入等
- payload 输出：文本链路径、JSON（带工具名与版本）、payload 落盘；终端里 NUL 字节转义显示，`--output` 仍写原始字节
- 入口语义：从 `__invoke` / `__toString` / `__get` 等非反序列化魔术方法起步的链会被标注"需要外部触发"，避免把必须被调用才生效的 payload 当成独立 gadget
- CLI 校验：路径与数值选项校验，退出码清晰（0 找到链、1 未找到链、2 输入不合法）

## 已知限制

静态分析不可能覆盖 PHP 的全部动态特性。当前版本不处理：

- 反射、`eval` 后生成的动态代码、运行时才确定的 `include` 路径
- 控制流可达性（`if` 条件、异常路径、循环次数）
- `preg_replace_callback`：回调拿到的是匹配数组而不是普通元素，不建立形参映射
- sink 参数涉及多个不同属性时，只把第一个可控属性写入 payload
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
│   ├── Parser/               # AST → IR
│   ├── Model/                # 类/方法/属性/调用点/链/边
│   ├── Analysis/             # sink 识别 + 链搜索
│   └── Payload/              # 对象图 → 手写序列化
├── examples/demo.php         # 示例漏洞代码
├── tests/run.php             # 测试套件
├── tests/fixtures/           # 解析/链搜索用例
├── composer.json             # 依赖与自动加载定义
├── README.zh-CN.md           # 中文文档
├── LICENSE                   # MIT
└── README.md
```

## 测试

```bash
php tests/run.php
```

测试覆盖解析层、链搜索、payload 构建和 CLI。其中有几条用例会把 payload 真正反序列化、确认 sink 确实被触发——链到底能不能用，只有真跑一遍才知道。此外还检查 trait、继承、局部污点、`__get` / `__isset` 边、命名空间、private/protected 序列化键，以及 CLI 的各条输入路径。

## 安全声明

本工具仅用于**授权的 CTF 竞赛、教学和自有系统审计**。生成的 payload 会真实触发目标代码中的危险函数，请勿用于未授权的系统。

## License

[MIT](LICENSE) © 2026 guaidao2 & coolmoon
