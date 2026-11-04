<?php

declare(strict_types=1);

/**
 * 靶场回归测试。
 *
 * 单文件用例：文件顶部用 `// EXPECT: XSS,SQLI` 或 `// EXPECT: none` 声明期望。
 * 多文件项目：目录下 expect.json 声明每个文件期望的漏洞类别。
 *
 * 用法：php tests/bench/run.php [--filter=substr]
 */

require __DIR__ . '/../../../src/bootstrap.php';

use PopChain\Symbolic\Engine\Analyzer;
use PopChain\Symbolic\Engine\AnalyzerOptions;
use PopChain\Symbolic\Security\Finding;

$filter = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

$analyzer = new Analyzer(new AnalyzerOptions(timeout: 20.0));
$passed = 0;
$failed = 0;
$failures = [];

/**
 * @return list<string>|null
 */
function expectedCategories(string $file): ?array
{
    $source = (string) file_get_contents($file);
    if (!preg_match('/EXPECT:[ \t]*([A-Za-z_, \t]+)/', $source, $match)) {
        return null;
    }
    $value = trim($match[1]);
    if (strtolower($value) === 'none') {
        return [];
    }

    return array_values(array_unique(array_filter(
        array_map('trim', preg_split('/[,\s]+/', $value) ?: []),
        static fn (string $item): bool => $item !== '',
    )));
}

/**
 * @param list<Finding> $findings
 * @return list<string>
 */
function categoriesOf(array $findings): array
{
    $categories = array_values(array_unique(array_map(
        static fn (Finding $finding): string => $finding->category,
        $findings,
    )));
    sort($categories);

    return $categories;
}

function reportFailure(string $name, array $expected, array $actual, array $findings): void
{
    global $failures;
    sort($expected);
    $line = sprintf(
        'FAIL %s — expected [%s], got [%s]',
        $name,
        implode(', ', $expected),
        implode(', ', $actual),
    );
    $failures[] = $line;
    echo $line . "\n";
    foreach ($findings as $finding) {
        echo sprintf(
            "     %s:%d %s %s taint=%s\n",
            basename($finding->file),
            $finding->line,
            $finding->category,
            $finding->sink,
            implode(',', $finding->taint),
        );
    }
}

// ---------------------------------------------------------------------------
// 单文件用例
// ---------------------------------------------------------------------------

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);

foreach ($cases as $file) {
    $name = basename($file);
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $expected = expectedCategories($file);
    if ($expected === null) {
        continue;
    }

    $result = $analyzer->analyze([$file]);
    $actual = categoriesOf($result->findings);

    if ($expected === $actual) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        reportFailure($name, $expected, $actual, $result->findings);
    }
}

// ---------------------------------------------------------------------------
// 多文件项目用例
// ---------------------------------------------------------------------------

$projects = glob(__DIR__ . '/projects/*', GLOB_ONLYDIR) ?: [];
sort($projects);

foreach ($projects as $directory) {
    $name = 'projects/' . basename($directory);
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $expectFile = $directory . '/expect.json';
    if (!is_file($expectFile)) {
        continue;
    }
    $expect = json_decode((string) file_get_contents($expectFile), true);
    if (!is_array($expect) || !isset($expect['files'])) {
        continue;
    }

    $result = $analyzer->analyze([$directory]);
    $byFile = [];
    foreach ($result->findings as $finding) {
        $relative = ltrim(str_replace($directory, '', $finding->file), '/');
        $byFile[$relative][] = $finding->category;
    }

    $ok = true;
    foreach ($expect['files'] as $relative => $categories) {
        $expected = array_values(array_unique($categories));
        sort($expected);
        $actual = array_values(array_unique($byFile[$relative] ?? []));
        sort($actual);
        if ($expected !== $actual) {
            $ok = false;
            $failed++;
            reportFailure(
                $name . '/' . $relative,
                $expected,
                $actual,
                array_values(array_filter(
                    $result->findings,
                    static fn (Finding $finding): bool => basename($finding->file) === basename($relative),
                )),
            );
        }
    }
    // 未在期望中声明的文件不应产生 finding
    foreach ($byFile as $relative => $categories) {
        if (!array_key_exists($relative, $expect['files']) && $categories !== []) {
            $ok = false;
            $failed++;
            reportFailure($name . '/' . $relative . ' (undeclared)', [], array_values(array_unique($categories)), []);
        }
    }
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
    }
}

echo "\n----------------------------------------\n";
echo "bench passed: {$passed}, failed: {$failed}\n";
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
}

exit($failed === 0 ? 0 : 1);
