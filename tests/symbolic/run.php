<?php

declare(strict_types=1);

/**
 * 符号执行引擎测试入口。
 *
 * 用法：php tests/run.php [--filter=substr]
 *
 * 项目不依赖 PHPUnit，保持零依赖即可运行（只需要 php-parser）。
 */

require __DIR__ . '/../../src/bootstrap.php';

use PopChain\Symbolic\Engine\Analyzer;
use PopChain\Symbolic\Engine\AnalyzerOptions;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Security\SourceCatalog;
use PopChain\Symbolic\Solver\Solver;
use PopChain\Symbolic\Solver\Status;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Value\ArrayValue;
use PopChain\Symbolic\Value\ValueFactory;

final class T
{
    public static int $passed = 0;

    public static int $failed = 0;

    /** @var list<string> */
    public static array $failures = [];

    public static string $filter = '';

    public static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n== {$name} ==\n";
    }

    public static function ok(bool $condition, string $name, string $detail = ''): void
    {
        if (self::$filter !== '' && !str_contains(self::$section . ' ' . $name, self::$filter)) {
            return;
        }
        if ($condition) {
            self::$passed++;
            echo "  ok   {$name}\n";
        } else {
            self::$failed++;
            $message = $name . ($detail !== '' ? ' — ' . $detail : '');
            self::$failures[] = $message;
            echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        }
    }

    public static function same(mixed $expected, mixed $actual, string $name): void
    {
        $ok = $expected === $actual;
        self::ok(
            $ok,
            $name,
            $ok ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }

    public static function contains(string $needle, string $haystack, string $name): void
    {
        $ok = str_contains($haystack, $needle);
        self::ok($ok, $name, $ok ? '' : "expected to contain '{$needle}', got '{$haystack}'");
    }

    public static function summary(): int
    {
        echo "\n----------------------------------------\n";
        echo 'passed: ' . self::$passed . ', failed: ' . self::$failed . "\n";
        if (self::$failures !== []) {
            echo "\nFailures:\n";
            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . "\n";
            }
        }

        return self::$failed === 0 ? 0 : 1;
    }
}

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        T::$filter = substr($arg, strlen('--filter='));
    }
}

// ---------------------------------------------------------------------------
// 表达式与常量折叠
// ---------------------------------------------------------------------------

T::section('表达式 / 常量折叠');
{

    $sum = Expr::bin('+', Expr::literal(1), Expr::literal(2));
    T::ok($sum->isConstant() && $sum->constantValue() === 3, '整数字面量折叠');
    T::same('abcd', Expr::bin('.', Expr::literal('ab'), Expr::literal('cd'))->constantValue(), '字符串拼接折叠');
    T::same(0, Expr::bin('*', Expr::literal(0), Expr::literal(7))->constantValue(), '乘法折叠');
    T::same(3, Expr::call('strlen', [Expr::literal('abc')])->constantValue(), '纯函数常量折叠');

    $x = Expr::variable("\$_GET['id']");
    $oneMore = Expr::bin('+', $x, Expr::literal(0));
    T::same($x->canonical(), $oneMore->canonical(), 'x + 0 化简为 x');

    $empty = Expr::bin('.', Expr::literal(''), $x);
    T::ok(!$empty->isConstant(), '空串拼接保留符号');

    $notEqual = Expr::unary('!', Expr::bin('==', $x, Expr::literal('admin')));
    T::same("(\$_GET['id'] != 'admin')", $notEqual->toString(), '!(a == b) 化简为 a != b');

    $self = Expr::bin('==', $x, Expr::variable("\$_GET['id']"));
    T::same(true, $self->constantValue(), 'x == x 折叠为 true');

    $cond = Expr::bin('>', Expr::call('strlen', [$x]), Expr::literal(3));
    T::same("(strlen(\$_GET['id']) > 3)", $cond->toString(), '约束表达式可读形式');

    T::ok($cond->contains("\$_GET['id']"), '表达式变量收集');
    T::ok($cond->variables() === ["\$_GET['id']"], '表达式变量列表');
}

// ---------------------------------------------------------------------------
// 值与污点
// ---------------------------------------------------------------------------

T::section('值 / 污点');
{

    $env = SourceCatalog::superglobals();
    T::ok(isset($env['$_GET']), '超全局变量已初始化');

    $get = $env['$_GET'];
    $name = $get->get('name');
    T::ok($name->isTainted(), '$_GET 的未知键带有污点');
    T::same("\$_GET['name']", $name->describe(), '输入值的可读表达式');

    $array = new ArrayValue(['k' => ValueFactory::fromConstant(42)]);
    T::same(42, $array->get('k')->toExpr()->constantValue(), '抽象数组读取常量');

    $merged = ValueFactory::merge([ValueFactory::fromConstant(1), ValueFactory::fromConstant(1)]);
    T::same(1, $merged->toExpr()->constantValue(), '相同常量合并');

    $different = ValueFactory::merge([ValueFactory::fromConstant(1), ValueFactory::fromConstant(2)]);
    T::ok(!$different->toExpr()->isConstant(), '不同常量合并为未知');

    $tainted = Taint::of(SourceCatalog::source("\$_GET['a']"));
    $clean = $tainted->without(['GET']);
    T::ok($clean->isEmpty(), '按类别净化污点');
}

// ---------------------------------------------------------------------------
// 约束求解器
// ---------------------------------------------------------------------------

T::section('约束求解器');
{
    $solver = new Solver();
    $x = Expr::variable("\$_GET['a']");
    $y = Expr::variable('$y');
    $len = static fn (string $op, int $n): Expr => Expr::bin($op, Expr::call('strlen', [$x]), Expr::literal($n));

    T::same(Status::Sat, $solver->check([]), '空约束可满足');
    T::same(Status::Unsat, $solver->check([Expr::literal(false)]), '常量 false 不可满足');
    T::same(Status::Sat, $solver->check([Expr::literal(true)]), '常量 true 可满足');

    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('admin')),
            Expr::bin('===', $x, Expr::literal('guest')),
        ]),
        '同一变量两个不同严格绑定',
    );

    T::same(
        Status::Sat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('admin')),
            Expr::bin('===', $x, Expr::literal('admin')),
        ]),
        '相同绑定可满足',
    );

    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('admin')),
            Expr::bin('!==', $x, Expr::literal('admin')),
        ]),
        '绑定与不等冲突',
    );

    T::same(
        Status::Unsat,
        $solver->check([Expr::bin('>', $x, Expr::literal(5)), Expr::bin('<', $x, Expr::literal(3))]),
        '整数区间冲突',
    );
    T::same(
        Status::Sat,
        $solver->check([Expr::bin('>', $x, Expr::literal(3)), Expr::bin('<', $x, Expr::literal(5))]),
        '整数区间可满足',
    );
    T::same(
        Status::Unsat,
        $solver->check([Expr::bin('>', $x, Expr::literal(3)), Expr::bin('===', $x, Expr::literal(3))]),
        '绑定值落在区间外',
    );

    T::same(Status::Unsat, $solver->check([$len('>', 3), $len('<', 2)]), '长度区间冲突');
    T::same(Status::Sat, $solver->check([$len('>', 1), $len('<', 5)]), '长度区间可满足');
    T::same(
        Status::Unsat,
        $solver->check([Expr::bin('===', $x, Expr::literal('abc')), $len('===', 2)]),
        '绑定字符串长度不符',
    );
    T::same(
        Status::Sat,
        $solver->check([Expr::bin('===', $x, Expr::literal('abc')), $len('===', 3)]),
        '绑定字符串长度相符',
    );

    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::call('str_contains', [$x, Expr::literal('z')]),
        ]),
        '绑定字符串不包含所需子串',
    );
    T::same(
        Status::Sat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::call('str_contains', [$x, Expr::literal('b')]),
        ]),
        '绑定字符串包含所需子串',
    );
    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::unary('!', Expr::call('str_contains', [$x, Expr::literal('b')])),
        ]),
        '否定字符串谓词与绑定冲突',
    );
    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::call('str_starts_with', [$x, Expr::literal('z')]),
        ]),
        '前缀约束冲突',
    );

    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::call('preg_match', [Expr::literal('/^z/'), $x]),
        ]),
        '正则约束冲突',
    );
    T::same(
        Status::Sat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('abc')),
            Expr::call('preg_match', [Expr::literal('/^a/'), $x]),
        ]),
        '正则约束可满足',
    );

    T::same(
        Status::Unsat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('c')),
            Expr::call('in_array', [$x, Expr::literal(['a', 'b'])]),
        ]),
        '枚举约束冲突',
    );
    T::same(
        Status::Sat,
        $solver->check([
            Expr::bin('===', $x, Expr::literal('a')),
            Expr::call('in_array', [$x, Expr::literal(['a', 'b'])]),
        ]),
        '枚举约束可满足',
    );

    T::same(
        Status::Unsat,
        $solver->check([Expr::call('is_int', [$x]), Expr::call('is_string', [$x])]),
        '互斥类型谓词',
    );
    T::same(
        Status::Unsat,
        $solver->check([Expr::bin('===', $x, Expr::literal('abc')), Expr::call('is_int', [$x])]),
        '类型谓词与绑定冲突',
    );

    $or = Expr::bin(
        '||',
        Expr::bin('===', $x, Expr::literal('a')),
        Expr::bin('===', $x, Expr::literal('b')),
    );
    T::same(
        Status::Unsat,
        $solver->check([$or, Expr::bin('===', $x, Expr::literal('c'))]),
        'OR 候选全部冲突',
    );
    T::same(
        Status::Sat,
        $solver->check([$or, Expr::bin('===', $x, Expr::literal('a'))]),
        'OR 候选之一可满足',
    );

    $notOr = Expr::unary('!', $or);
    T::same(
        Status::Unsat,
        $solver->check([$notOr, Expr::bin('===', $x, Expr::literal('a'))]),
        'De Morgan 取反后与绑定冲突',
    );

    T::same(
        Status::Unknown,
        $solver->check([Expr::bin('==', $x, $y)]),
        '无法判定的约束返回 UNKNOWN',
    );
}

// ---------------------------------------------------------------------------
// 集成分析
// ---------------------------------------------------------------------------

T::section('集成分析');
{
    $analyzer = new Analyzer(new AnalyzerOptions(timeout: 15.0));
    $result = $analyzer->analyze([__DIR__ . '/cases']);

    $byFile = [];
    foreach ($result->findings as $finding) {
        $byFile[basename($finding->file)][] = $finding;
    }

    $countCategory = static function (string $file, string $category) use ($byFile): int {
        $findings = $byFile[$file] ?? [];

        return count(array_filter(
            $findings,
            static fn ($finding): bool => $finding->category === $category,
        ));
    };

    T::same(1, $countCategory('xss.php', 'XSS'), 'XSS 检出');
    T::same(1, $countCategory('sqli.php', 'SQLI'), 'SQL 注入检出');
    T::same(1, $countCategory('rce.php', 'RCE'), '命令注入检出');
    T::same(1, $countCategory('lfi.php', 'LFI'), '文件包含检出');
    T::same(1, $countCategory('function.php', 'XSS'), '跨函数污点传播');
    T::same(1, $countCategory('loop.php', 'XSS'), '循环内污点传播');
    T::same(1, $countCategory('class.php', 'XSS'), '类方法内污点传播');
    T::same(1, $countCategory('lib.php', 'XSS'), 'include 后函数调用');
    T::same(2, $countCategory('unserialize_property_read.php', 'SSRF'), '反序列化属性进入文件读取');
    T::same(1, $countCategory('unserialize_property_read.php', 'OBJECT_INJECTION'), '反序列化入口本身');

    T::same(0, count($byFile['prune.php'] ?? []), '不可达分支与净化分支不报告');
    T::same(0, count($byFile['safe.php'] ?? []), '安全写法不报告');
    T::same(0, count($byFile['main.php'] ?? []), 'include 入口本身无直接漏洞');
    T::same(0, count($byFile['realpath_safe.php'] ?? []), 'realpath 结果不作为 SSRF 源');
    T::same(0, count($byFile['str_replace_traversal.php'] ?? []), 'str_replace 删除分隔符后不报告路径穿越');
    T::same(0, count($byFile['header_blocked.php'] ?? []), 'header() 拒绝 CRLF，不报告头注入');
    T::same(0, count($byFile['realpath_confined_read.php'] ?? []), 'realpath 包含性守卫后不报告文件读取');
    T::same(0, count($byFile['realpath_constant_base.php'] ?? []), '常量表达式根目录可识别为包含性守卫');
    T::same(1, $countCategory('realpath_weak_prefix.php', 'SSRF'), '弱前缀检查不能当作 realpath 包含性守卫');
    T::ok($countCategory('realpath_root_base.php', 'SSRF') >= 1, 'realpath(/) 不能当作有效包含性校验');
    T::ok($countCategory('realpath_negative_guard.php', 'SSRF') >= 1, '越过包含范围的分支仍要报告文件读取');
    T::ok($countCategory('realpath_else_read.php', 'SSRF') >= 1, 'else 分支读取仍要报告');
    T::ok($countCategory('realpath_nonterminating_guard.php', 'SSRF') >= 1, '守卫分支不终止时仍要报告');
    T::ok($countCategory('realpath_one_side_separator.php', 'SSRF') >= 1, '只给一侧补分隔符仍要报告');
    T::ok($countCategory('realpath_unbound_operand.php', 'SSRF') >= 1, 'realpath 检查与读取不是同一个表达式时仍要报告');
    T::ok($countCategory('realpath_conjunction_guard.php', 'SSRF') >= 1, '合取守卫不能当作无条件包含性校验');
    T::ok($countCategory('realpath_conjunction_guard_and.php', 'SSRF') >= 1, 'and 合取守卫也不能当作包含性校验');
    T::ok($countCategory('realpath_wrapped_subject.php', 'SSRF') >= 1, 'subject 被包装后不能当作包含性校验');
    T::same(0, count($byFile['realpath_split_checks.php'] ?? []), '拆成两个 if 的包含性校验仍算有效');
    T::same(0, count($byFile['realpath_else_authorized.php'] ?? []), 'else 安全分支不报告');
    T::same(0, count($byFile['realpath_positive_and.php'] ?? []), '正向包含性事实在 && 合取里也识别');
    T::same(0, count($byFile['realpath_yoda.php'] ?? []), 'Yoda 写法的包含性检查也识别');
    T::ok($countCategory('realpath_basename_guard.php', 'SSRF') >= 1, '守卫 basename 后的路径但读取原始值时仍要报告');
    T::ok($countCategory('realpath_variable_base.php', 'SSRF') >= 1, '变量 base 不能当作包含性校验');
    T::same(1, $countCategory('mail_headers.php', 'HEADER'), 'mail 第 4 个参数是头注入位');
    T::same(1, $countCategory('urldecode_header.php', 'HEADER'), 'urldecode 会恢复 HEADER 污点');
    T::same(1, $countCategory('urldecode_xss.php', 'XSS'), 'urldecode 会恢复 XSS 污点');
    T::same(1, $countCategory('str_replace_dotdot.php', 'PATH_TRAVERSAL'), 'str_replace 删除 .. 不算分隔符净化');
    T::same(0, $countCategory('mail_body.php', 'HEADER'), 'mail 正文不按 header injection 报告');
    T::same(28, count($result->findings), '漏洞去重后总数');
    T::ok($result->pathCount > 0, '路径计数大于 0');
    T::ok($result->fileCount >= 11, '文件计数覆盖测试用例');

    $xss = $byFile['xss.php'][0] ?? null;
    T::ok($xss !== null, 'XSS finding 存在');
    T::ok($xss !== null && $xss->path !== [], 'finding 携带路径条件');
    T::ok(
        $xss !== null && str_contains(implode(' && ', $xss->path), "strlen(\$_GET['name']) > 0"),
        '路径条件可读且正确',
    );
    T::ok($xss !== null && $xss->snippet !== '', 'finding 携带源码片段');
    T::ok($xss !== null && $xss->payload !== '', 'finding 携带 payload 提示');
    T::ok($xss !== null && in_array("\$_GET['name']", $xss->taint, true), 'finding 携带污点来源');

    $rce = $byFile['rce.php'][0] ?? null;
    T::ok(
        $rce !== null && str_contains(implode(' && ', $rce->path), 'isset'),
        'isset 路径条件保留可读形式',
    );

    $json = json_decode((string) json_encode($result->toArray()), true);
    T::ok(is_array($json) && isset($json['findings'], $json['stats']), 'JSON 报告可序列化');
    T::ok(isset($json['stats']['solver']['checks']), 'JSON 包含求解器统计');

    $single = $analyzer->analyze([__DIR__ . '/cases/safe.php']);
    T::same(0, count($single->findings), '单文件安全样例无漏洞');

    $pruned = $analyzer->analyze([__DIR__ . '/cases/prune.php']);
    T::same(0, count($pruned->findings), '单文件剪枝样例无漏洞');
    T::ok($pruned->solverStats['pruned'] > 0, '求解器实际剪枝了不可达分支');
}

exit(T::summary());
