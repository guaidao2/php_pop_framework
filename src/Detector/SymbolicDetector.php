<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Model\ClassInfo;
use PopChain\Symbolic\Engine\Analyzer;
use PopChain\Symbolic\Engine\AnalyzerOptions;

/**
 * 符号执行检测器：调用合并进仓库的 SymPHP 引擎做路径敏感污点分析。
 *
 * 和反序列化检测器的区别：这一层直接扫源码，覆盖 XSS / SQL 注入 / 命令注入 /
 * 文件包含 / SSRF / XXE / 反序列化等多个类别，结果是"文件 + 行 + 污点链"，
 * 不是 POP 链。
 */
final class SymbolicDetector implements DetectorInterface
{
    /** @var list<string> */
    private array $warnings = [];

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function name(): string
    {
        return 'symbolic';
    }

    public function description(): string
    {
        return 'Path-sensitive taint analysis via the merged SymPHP engine (XSS/SQLi/RCE/LFI/SSRF/XXE/deserialization)';
    }

    /** @return list<Finding> */
    public function detect(array $classes, array $options = []): array
    {
        $path = (string) ($options['path'] ?? '.');
        $analyzerOptions = AnalyzerOptions::fromArray([
            'timeout' => (float) ($options['sym_timeout'] ?? 30.0),
            'maxPaths' => (int) ($options['sym_max_paths'] ?? 2000),
            'maxStates' => (int) ($options['sym_max_states'] ?? 128),
            'maxCallDepth' => (int) ($options['sym_max_depth'] ?? 8),
            'maxLoopIterations' => (int) ($options['sym_max_loop'] ?? 3),
            'followIncludes' => (bool) ($options['sym_follow_includes'] ?? true),
        ]);

        $entry = isset($options['sym_entry']) && is_string($options['sym_entry']) && $options['sym_entry'] !== ''
            ? (string) $options['sym_entry']
            : null;

        $result = (new Analyzer($analyzerOptions))->analyze([$path], $entry);

        $this->warnings = [];
        if ($result->truncated) {
            $this->warnings[] = 'symbolic analysis was truncated (path/state/timeout/depth budget); results may be incomplete (increase --sym-max-paths / --sym-max-states)';
        }
        foreach ($result->parseErrors as $parseError) {
            $this->warnings[] = 'symbolic parse error: ' . $parseError;
        }

        $findings = [];
        foreach ($result->findings as $finding) {
            $findings[] = new Finding(
                type: $this->name(),
                title: $finding->title,
                chain: null,
                notes: [],
                feasible: true,
                severity: $finding->severity,
                category: $finding->category,
                file: $finding->file,
                line: $finding->line,
                sink: $finding->sink,
                taint: $finding->taint,
                path: $finding->path,
                payload: $finding->payload,
                snippet: $finding->snippet,
            );
        }
        return $findings;
    }
}
