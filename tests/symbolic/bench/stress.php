<?php

declare(strict_types=1);

/**
 * 压力与健壮性测试（运行时在系统临时目录生成文件，不污染仓库）。
 *
 * 用法：php tests/bench/stress.php
 */

require __DIR__ . '/../../../src/bootstrap.php';

use PopChain\Symbolic\Engine\Analyzer;
use PopChain\Symbolic\Engine\AnalyzerOptions;

$root = sys_get_temp_dir() . '/symphp_stress_' . getmypid();
@mkdir($root, 0777, true);

$failures = 0;

$report = static function (string $name, bool $ok, string $detail) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ' — ' . $detail . "\n";
    if (!$ok) {
        $failures++;
    }
};

// 1. 大量文件
$many = $root . '/many';
@mkdir($many, 0777, true);
for ($i = 0; $i < 200; $i++) {
    file_put_contents(
        $many . '/file' . $i . '.php',
        "<?php\n\$x = \$_GET['x'];\nif (strlen(\$x) > 3) {\n    echo \$x;\n}\n",
    );
}
$start = microtime(true);
$result = (new Analyzer(new AnalyzerOptions(timeout: 30.0)))->analyze([$many]);
$elapsed = microtime(true) - $start;
$report(
    '200 files',
    $elapsed < 20.0 && count($result->findings) === 200 && !$result->truncated,
    sprintf('%.2fs, findings=%d, files=%d, truncated=%s', $elapsed, count($result->findings), $result->fileCount, $result->truncated ? 'yes' : 'no'),
);

// 2. 十层条件分支
$branches = $root . '/branches.php';
$code = "<?php\n\$x = \$_GET['x'];\n";
for ($i = 0; $i < 10; $i++) {
    $code .= "if (\$x !== 'v{$i}') {\n";
}
$code .= "echo \$x;\n";
$code .= str_repeat("}\n", 10);
file_put_contents($branches, $code);
$start = microtime(true);
$result = (new Analyzer(new AnalyzerOptions(timeout: 20.0, maxStates: 64)))->analyze([$branches]);
$elapsed = microtime(true) - $start;
$report(
    '10-level branches',
    $elapsed < 10.0 && count($result->findings) > 0,
    sprintf('%.2fs, findings=%d, truncated=%s, states-limited', $elapsed, count($result->findings), $result->truncated ? 'yes' : 'no'),
);

// 3. 无限循环 + 时间预算
$loop = $root . '/loop.php';
file_put_contents($loop, "<?php\n\$i = 0;\nwhile (true) {\n    \$i++;\n    echo \$_GET['x'];\n}\n");
$start = microtime(true);
$result = (new Analyzer(new AnalyzerOptions(timeout: 5.0, maxLoopIterations: 3)))->analyze([$loop]);
$elapsed = microtime(true) - $start;
$report(
    'infinite loop',
    $elapsed < 8.0 && count($result->findings) > 0,
    sprintf('%.2fs, findings=%d, truncated=%s', $elapsed, count($result->findings), $result->truncated ? 'yes' : 'no'),
);

// 4. 语法错误与空文件
$broken = $root . '/broken.php';
file_put_contents($broken, "<?php\nfunction broken( {\n");
$empty = $root . '/empty.php';
file_put_contents($empty, "<?php\n");
$result = (new Analyzer(new AnalyzerOptions(timeout: 10.0)))->analyze([$broken, $empty]);
$report(
    'parse error handling',
    count($result->parseErrors) === 1 && count($result->findings) === 0,
    'parseErrors=' . count($result->parseErrors) . ', findings=' . count($result->findings),
);

// 5. 大文件（2000 条语句）
$large = $root . '/large.php';
$code = "<?php\n\$x = \$_GET['x'];\n";
for ($i = 0; $i < 2000; $i++) {
    $code .= "\$v{$i} = 'const{$i}';\n";
}
$code .= "echo \$x;\n";
file_put_contents($large, $code);
$start = microtime(true);
$result = (new Analyzer(new AnalyzerOptions(timeout: 15.0)))->analyze([$large]);
$elapsed = microtime(true) - $start;
$report(
    'large file',
    $elapsed < 10.0 && count($result->findings) === 1,
    sprintf('%.2fs, findings=%d, statements=%d', $elapsed, count($result->findings), $result->statementCount),
);

// 清理
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}
@rmdir($root);

echo "\nstress failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
