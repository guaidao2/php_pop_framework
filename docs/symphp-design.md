# SymPHP 设计说明

本文描述 SymPHP 的内部结构、关键算法与设计取舍。

## 1. 目标与范围

SymPHP 的目标是 **在真实 PHP 代码上做路径敏感的污点分析**，服务于代码审计与 CI。
它不试图成为完整的 PHP 解释器或通用 SMT 前端，而是围绕下面这条链路优化：

```
源代码 ──php-parser──▶ AST ──符号执行──▶ 状态树 ──约束求解──▶ 可达路径 ──sink 匹配──▶ Finding
```

## 2. 总体架构

```
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│  Analyzer    │──▶│  Program     │──▶│  Interpreter │
│ 文件收集/解析 │   │ 函数/类索引   │   │  AST 解释执行 │
└──────────────┘   └──────────────┘   └──────┬───────┘
                                             │
                    ┌────────────────────────┼────────────────────────┐
                    ▼                        ▼                        ▼
             ┌────────────┐          ┌────────────┐          ┌────────────┐
             │   State    │          │   Solver   │          │  Security  │
             │ 环境/约束   │          │  SAT/UNSAT │          │ sink/source │
             └────────────┘          └────────────┘          └────────────┘
```

| 模块 | 职责 |
| --- | --- |
| `Engine\Analyzer` | 收集文件、解析 AST、建立索引、逐文件启动执行 |
| `Engine\Program` | 跨文件索引函数、类、方法、文件顶层语句与 `use` 别名 |
| `Engine\Interpreter` | 执行语句与表达式，fork 状态，处理控制流与函数内联 |
| `Engine\State` | 当前作用域环境、路径约束、控制流信号、预算计数 |
| `Engine\Builtins` | 内置函数的返回类型与污点传播规则 |
| `Solver\Solver` | 路径约束可满足性检查（三态） |
| `Security\*` | sink 目录、输入源目录、净化器、Finding |
| `Report\*` / `CLI\Application` | 输出与命令行 |

## 3. 符号值模型

运行时值（`Value`）是不可变对象：

- `ScalarValue`：内部是一个符号表达式 `Expr`
- `ArrayValue`：逐键保存 `Value`，可标记为 `open`（可能还有未知键）
- `ObjectValue`：类名 + 属性 `Value`
- `ClosureValue`：闭包 AST + 捕获环境
- `UnknownValue`：无法确定的值，保留唯一符号名

表达式（`Expr`）包括：

```
ConstExpr   常量
VarExpr     符号变量（$_GET['a']、$x、getenv('X')）
BinaryExpr  二元运算（+ - . == === && ?? ...）
UnaryExpr   一元运算与类型转换（! (int) (string) ...）
CallExpr    函数调用结果（strlen($x)）
ArrayAccessExpr
TernaryExpr
```

所有表达式通过工厂构造，并立即经过 `Simplifier`：

- 常量折叠；
- 代数化简（`x + 0 → x`、`'' . x → (string)x`）；
- 保持短路语义（`false && 副作用` 不删除右侧）；
- `!(a == b) → a != b`，便于求解器处理；
- 自比较（`x == x → true`，浮点除外）。

不可变值让状态 fork 只需复制数组，不需要深拷贝。

## 4. 污点模型

`Taint` 保存：

1. 来源集合（`$_GET['a']`、`$_POST['b']` …）；
2. 已净化的 **漏洞类别** 集合。

```php
$taint->isTaintedFor('XSS');        // 是否仍可能造成 XSS
$taint->sanitizeFor(['SQLI']);      // addslashes() 的效果
$taint->union($other);              // 两边都净化才算净化
```

净化按类别而非按来源，因此 `htmlspecialchars($_GET['x'])` 仍可用于 SQL 拼接
（会继续报告 SQLI），但不会报告 XSS。这比"净化后一律干净"更贴近实际审计。

## 5. 路径探索

`Interpreter::executeStmts()` 返回 `State[]`：

```
for each statement:
    next = []
    for each state:
        if terminated: keep
        else: next += executeStmt(stmt, state)
    states = limit(next)
```

分支处：

1. 求值条件表达式并化简；
2. 若为常量 → 只走一条；
3. 否则 fork：
   - then 分支加入条件；
   - else 分支加入 `!条件`；
4. 用 `Solver::check()` 检查整个约束集：
   - `UNSAT` → 丢弃该状态（剪枝）；
   - `SAT` / `UNKNOWN` → 继续。

`UNKNOWN` 不剪枝是核心安全策略：求解器不精确时宁可多探索，也不漏报。

## 6. 约束求解器

`Solver` 接受一组布尔表达式（AND），返回三态。

### 6.1 规范化

- 常量折叠；
- OR 展开为若干合取候选（上限 64），任一候选 SAT 即 SAT；
- De Morgan 把 `!(a && b)` 转成 `!a || !b`；
- 所有比较把常量放到右侧。

### 6.2 抽象域

每个变量维护：

| 抽象 | 内容 |
| --- | --- |
| 常量绑定 | `$x === 'admin'`；区分严格/宽松绑定 |
| 不等值 | `$x !== 'guest'` |
| 整数区间 | `$x > 5`、`$x <= 10` |
| 长度区间 | `strlen($x) > 3` |
| 长度排除 | `strlen($x) != 4` |
| 字符串谓词 | `str_contains`、`str_starts_with`、`str_ends_with` |
| 正则 | `preg_match('/^a/', $x)`（绑定后实际匹配验证） |
| 枚举 | `in_array($x, ['a','b'])` |
| 类型谓词 | `is_int`、`is_string`、`is_array` … |

`finish()` 统一验证：

- 区间为空 → UNSAT；
- 绑定冲突（严格的 `!==` 或宽松的 `!=`）→ UNSAT；
- 绑定值不满足长度/包含/前缀/正则/类型/枚举 → UNSAT；
- 所有枚举值都被排除 → UNSAT。

### 6.3 严格 vs 宽松

`$x == '1'` 不建立硬绑定，因为 `$x` 可能是整数 `1`。只有 `===` 建立精确绑定。
这样避免因 PHP 松散比较规则而错误剪枝。

### 6.4 不可判定

变量间关系、未知函数、动态键等进入 `unknown` 列表，最终返回 `UNKNOWN`。

## 7. 函数与方法

- **用户函数**：内联执行。新状态拥有独立局部环境（参数 + 超全局），
  返回值合并（相同表达式保留，不同则退化为未知值但合并污点）。
- **方法**：根据 `ObjectValue` 的类名在 `Program` 中查找，沿父类链与 trait 解析；
  `$this` 绑定到对象。方法内对 `$this` 属性的修改会写回接收者变量。
- **静态方法**：`self::` 使用定义类，`static::` 使用实际调用类（延迟静态绑定），
  `parent::` 沿父类链解析。
- **构造器**：`new Class(...)` 创建对象并执行 `__construct`；PHP 8 的构造器
  属性提升（`public string $name`）会自动写入对象属性。
- **匿名类**：`new class { ... }` 在解释器内构建临时 `ClassDef`，方法与属性可正常分析。
- **请求访问器**：`$request->input()`、`$this->input->get()`、`->query()`、`->param()`
  等常见框架访问器返回带 `REQUEST` 污点的输入值。
- **递归/深度**：超过 `maxCallDepth` 返回未知值并标记 `truncated`。
- **include/require**：路径为字面量且已索引时实际内联执行；否则作为 LFI sink
  检查，并返回未知值。included 文件在同一状态中只内联一次，避免循环。

函数内路径的分支约束不会回传给调用者（返回值已合并），
因此调用者后续条件只基于合并后的抽象值。这是精度与复杂度的折中。

## 8. 循环

`while` / `for` / `do-while` / `foreach` 统一展开 `maxLoopIterations` 次：

- 每次迭代对条件做分支判断；
- 循环可能退出的状态（`!cond`）加入结果；
- `break` / `continue` 通过状态信号处理，支持层级；
- 达到上限后剩余状态直接作为退出状态，并设置 `truncated`。

`foreach` 对已知数组逐元素展开（最多 `maxLoopIterations` 个），
对开放数组额外执行一次"未知元素"迭代。`foreach ($arr as &$v)` 的引用写回
会把循环体内对 `$v` 的赋值同步回数组元素。

## 9. 安全模型

- **sink**：`SinkCatalog` 定义类别、严重级别、检查参数下标、payload 提示。
- **source**：超全局数组在初始化时构造为 `ArrayValue(open=true, sourcePath=...)`，
  读取未知键时自动生成带污点的输入值；二维访问（如 `$_FILES['f']['name']`）
  通过表达式路径继续展开。
- **净化**：`Builtins` 在构造返回值时调用 `Taint::sanitizeFor()`。
- **Finding**：记录文件、行号、sink、污点来源、路径条件、payload、源码行，
  按 `file:line:category:sink:taint` 去重。

## 10. 预算与路径爆炸

| 选项 | 默认 | 作用 |
| --- | --- | --- |
| `maxStates` | 64 | 单个执行点的状态上限 |
| `maxPaths` | 500 | 全局分支上限 |
| `maxCallDepth` | 8 | 函数内联深度 |
| `maxLoopIterations` | 3 | 循环展开次数 |
| `timeout` | 30s | 墙钟时间 |

任一预算触发都会设置 `truncated=true`，报告顶部会提示结果可能不完整。

分析目录时默认跳过 `vendor`、`node_modules`、`.git`、`.idea`，
可通过 `--exclude=DIR,DIR` 覆盖；传空字符串表示不跳过任何目录。

## 11. 扩展指南

### 添加 sink

编辑 `src/Security/SinkCatalog.php`：

```php
$add(new Sink('my_sink', 'XSS', 'high', 'My XSS', [0], '<script>alert(1)</script>'));
```

`argIndexes` 为空表示检查全部参数。

### 添加净化器

编辑 `src/Security/SanitizerCatalog.php`：

```php
'my_escape' => ['SQLI'],
```

### 添加内置函数语义

在 `src/Engine/Builtins.php` 的 `RETURN_TYPES` 表添加返回类型；
如需特殊污点规则，在 `call()` 的 `switch` 中添加分支。

### 添加求解规则

在 `src/Solver/Solver.php` 的 `applyVarConstant()` / `applyCall()` 中扩展抽象域。

## 12. 已知限制

- 普通引用参数（`function f(&$x)` 写回调用者）未建模；
- 魔术方法（`__get`/`__set`/`__call`）、生成器、Fiber 未建模；
- 异常仅建模第一条 `catch`，多 `catch` 类型分派未区分，`finally` 简化处理；
- 不建模 trait 方法冲突解决、类常量表达式求值；
- 路径合并不保留分支间差异；
- 对未知函数采取"保留参数污点"的保守策略，可能产生误报；
- 求解器只处理常见谓词，复杂算术/位运算关系返回 `UNKNOWN`。

这些限制都朝"不漏报"的方向倾斜；如需更精确的结论，应结合人工审计。

## 13. 靶场与回归

`tests/bench/cases/` 下的每个单文件靶场在头部声明期望：

```php
<?php
// EXPECT: XSS
echo $_GET['name'];
```

`tests/bench/projects/` 下的多文件项目用 `expect.json` 按文件声明期望。
`php tests/bench/run.php` 会逐项运行并对比实际漏洞类别集合；
`php tests/bench/stress.php` 额外覆盖大目录、路径爆炸、无限循环、
语法错误与大文件等健壮性场景。

新增 sink/净化规则或修改求解器后，应至少运行：

```bash
php tests/run.php
php tests/bench/run.php
php tests/bench/stress.php
```
