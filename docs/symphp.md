# SymPHP — PHP 符号执行 / 污点分析

> 本引擎原生合并进 php_pop_framework，与项目其余代码共用命名空间和自动加载：
> - 源码在 `src/Symbolic/`，命名空间 `PopChain\Symbolic`；
> - 唯一 CLI 入口是 `popchain`；默认 `--type=all` 会同时跑反序列化和符号执行，只用本引擎时加 `--type=symbolic`；
> - 引擎自身测试：`php tests/symbolic/run.php`；
> - 引擎由 guaidao2 & coolmoon 设计。

SymPHP 是一个用 PHP 编写的、面向 **Web 安全审计** 的轻量级符号执行引擎。
它基于 [nikic/php-parser](https://github.com/nikic/PHP-Parser) 构建 AST，
对目标代码做路径敏感的解释执行，追踪外部输入（`$_GET`、`$_POST`、`php://input` 等）
到危险汇聚点（`echo`、`system`、`mysqli_query`、`include` 等）的数据流。

与传统正则/模式匹配扫描器不同，SymPHP 会：

- **区分路径**：只在可达的分支上报告漏洞；
- **求解约束**：用内置求解器剪掉自相矛盾的路径（如 `$x === 'a' && $x === 'b'`）；
- **跟踪净化**：`htmlspecialchars()`、`intval()`、`addslashes()` 等会按漏洞类别清除污点；
- **跨函数传播**：内联用户函数、方法与 `include` 的文件；
- **给出可复现信息**：报告包含污点来源、路径条件、payload 提示与源码行。

## 特性

| 能力 | 说明 |
| --- | --- |
| 路径敏感 | 分支 fork，使用约束求解器剪枝不可达路径 |
| 三态求解 | `SAT` / `UNSAT` / `UNKNOWN`；不确定时不剪枝，避免漏报 |
| 污点来源 | 8 个超全局变量、`php://input`、`getenv()`、`getallheaders()`、`$argv` |
| 漏洞类别 | XSS、SQL 注入、命令注入、代码执行、文件包含、路径穿越、SSRF、XXE、反序列化、响应头注入 |
| 净化规则 | `htmlspecialchars`→XSS、`addslashes`→SQLI、`escapeshellarg`→RCE、`basename`→LFI、`intval`→全部 |
| 函数建模 | 用户函数/方法/构造器内联、内置函数语义、闭包与箭头函数、`array_map` 等回调 |
| 净化补充 | `pathinfo` 的 basename/filename/extension 清路径穿越；`preg_replace` 白名单净化用行为探测（必要非充分，复杂上下文相关 pattern 仍可能保守放行） |
| 对象建模 | 属性链、静态属性、构造器属性提升、匿名类、trait、继承、延迟静态绑定 |
| 框架风格 | `$request->input()`、`$this->input->get()`、`request()->param()` 等请求访问器视为输入源 |
| 跨文件 | 解析并索引整个目录，字面量 `include`/`require` 实际内联 |
| 目录排除 | 默认跳过 `vendor`、`node_modules`、`.git`、`.idea`，可用 `--exclude` 调整 |
| 预算控制 | 最大状态数、路径数、函数深度、循环展开次数、时间上限 |
| 输出 | 彩色控制台报告与 JSON（便于 CI 集成） |

## 安装

需要 PHP >= 8.1 与 `nikic/php-parser` >= 5.0，由项目统一安装：

```bash
composer install
```

依赖探测在 `src/bootstrap.php` 里完成（vendor / Composer / 系统包三种来源），
引擎不再有独立的 bootstrap。

## 快速开始

```bash
./popchain find examples/symbolic/vuln --type=symbolic
```

输出示例：

```
[1] [CRITICAL] OS Command Injection  (symbolic/RCE)
    examples/symbolic/vuln/rce.php:9
    taint: $_POST['cmd']
    path: (strlen($_POST['cmd']) > 0)
    sink: system()
    payload: id
    | system($cmd);

[2] [HIGH] Reflected XSS  (symbolic/XSS)
    examples/symbolic/vuln/xss.php:9
    taint: $_GET['name']
    sink: echo()
    payload: <script>alert(document.domain)</script>
```

输出格式与反序列化检测统一：`[序号] [严重度] 标题  (类型/分类)`，后跟位置、污点/路径、sink、备注和 payload；JSON 里统一是 `findings` 数组。

### 引擎相关选项

```bash
# JSON 输出（用于 CI）
./popchain find ./src --type=symbolic --json > report.json

# 只分析入口文件，其余文件仅用于函数/类索引
./popchain find ./src --type=symbolic --sym-entry=public/index.php

# 调整预算
./popchain find ./src --type=symbolic --sym-max-paths=1000 --sym-max-states=128 --sym-max-loop=5 --sym-timeout=60

# 不内联 include/require
./popchain find ./src --type=symbolic --sym-no-includes
```

可用选项：

```
--sym-timeout=SECONDS     分析时间上限（默认 30）
--sym-max-paths=N         最大路径数（默认 2000）
--sym-max-states=N        每个执行点的最大状态数（默认 128）
--sym-max-depth=N         函数内联最大深度（默认 8）
--sym-max-loop=N          循环最大展开次数（默认 3）
--sym-entry=FILE          只分析指定入口文件（其余文件仅用于索引）
--sym-no-includes         不内联 include/require
```

其余通用选项（`--json`、目录跳过、`--lang` 等）沿用 popchain。

## 漏洞类别与 sink

| 类别 | 典型 sink |
| --- | --- |
| `XSS` | `echo`、`print`、`printf`、`print_r`、`var_dump` |
| `SQLI` | `mysqli_query`、`mysql_query`、`pg_query`、`PDO::query`、`->prepare` |
| `RCE` | `system`、`exec`、`shell_exec`、`passthru`、`popen`、`proc_open`、反引号 |
| `CODE_EXEC` | `eval`、`assert`、`create_function`、`call_user_func*`、`preg_replace /e` |
| `LFI` | `include`、`require` |
| `PATH_TRAVERSAL` | `file_put_contents`、`unlink`、`rename`、`copy`、`chmod` |
| `FILE_UPLOAD` | `move_uploaded_file`（目标名来自 `$_FILES`、且没有扩展名黑/白名单或固定安全后缀时） |
| `SSRF` | `file_get_contents`、`readfile`、`fopen`、`curl_init`、`fsockopen` |
| `XXE` | `simplexml_load_string`、`simplexml_load_file`、`DOMDocument::loadXML` |
| `OBJECT_INJECTION` | `unserialize` |
| `HEADER` | `mail`（`header` / `setcookie` 会拒绝 CRLF，不报告） |

## 测试

```bash
# 引擎单元 + 集成测试（71 项）
php tests/symbolic/run.php

# 主项目测试（含符号执行检测器集成用例）
php tests/run.php

# 对抗式靶场：186 个单文件用例 + 10 个多文件项目
php tests/symbolic/bench/run.php

# 压力与健壮性：大目录、路径爆炸、无限循环、语法错误、大文件
php tests/symbolic/bench/stress.php
```

测试不依赖 PHPUnit。靶场用例在文件头部用 `// EXPECT: XSS,SQLI` 或
`// EXPECT: none` 声明期望，多文件项目用 `expect.json`；任何回归都会以非零
退出码结束，适合直接接入 CI。

## 目录结构

```
src/Symbolic/
  Expr/                  符号表达式与简化器
  Symbolic/              类型、污点、未知值
  Value/                 运行时抽象值（标量/数组/对象/闭包）
  Solver/                路径约束求解器（三态）
  Engine/                状态、程序索引、AST 解释器、内置函数、分析器
  Security/              sink/source/净化器目录、漏洞发现
  Report/                控制台与 JSON 报告
tests/symbolic/
  run.php                单元 + 集成测试
  cases/                 集成测试 fixture
  bench/
    run.php              对抗式靶场（单文件 + 多文件项目）
    stress.php           压力与健壮性测试
    cases/               186 个单文件靶场
    projects/            10 个多文件项目靶场
examples/vuln/           可运行的漏洞/安全示例
docs/design.md           设计说明与已知限制
```

## 已知限制

符号执行天然面临路径爆炸与动态语言语义问题。SymPHP 的取舍是
**宁可少剪枝、不可漏报**，因此：

- 循环只展开有限次数，超出后标记 `truncated`；
- 普通引用参数（`function f(&$x)` 写回调用者）未建模；`foreach` 引用与构造器属性提升已支持；
- 魔术方法（`__get`/`__set`/`__call`）、生成器、Fiber 未建模；
- 异常只建模第一条 `catch`，多 `catch` 类型分派未区分；
- 多个返回值合并为抽象值，可能丢失返回路径之间的差异；
- 无来源信息的未知函数返回值保守保留参数污点，可能产生误报；
- 无法判定时返回 `UNKNOWN` 并继续探索，可能产生误报。

详见 [docs/design.md](docs/design.md)。

## 许可

MIT
