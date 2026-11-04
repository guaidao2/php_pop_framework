<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Report;

use PopChain\Symbolic\Engine\AnalysisResult;
use PopChain\Symbolic\Security\Finding;

/**
 * 控制台报告输出。
 */
final class ConsoleReporter
{
    private const SEVERITY_ORDER = [
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
        'info' => 4,
    ];

    public function render(AnalysisResult $result, string $target, bool $color = true): string
    {
        $lines = [];
        $lines[] = $this->paint('Symbolic engine — PHP Symbolic Execution / Taint Analysis', '1;36', $color);
        $lines[] = 'Target: ' . $target;
        $lines[] = sprintf(
            'Files: %d   Paths: %d   Statements: %d   Pruned states: %d   Unknown checks: %d   Time: %.2fs',
            $result->fileCount,
            $result->pathCount,
            $result->statementCount,
            $result->solverStats['pruned'],
            $result->solverStats['unknown'],
            $result->duration,
        );
        if ($result->truncated) {
            $lines[] = $this->paint('Note: 分析被路径/循环/时间预算截断，结果可能不完整', '33', $color);
        }
        foreach ($result->parseErrors as $error) {
            $lines[] = $this->paint('Parse error: ' . $error, '31', $color);
        }
        $lines[] = '';

        if ($result->findings === []) {
            $lines[] = $this->paint('No vulnerabilities found.', '32', $color);
            $lines[] = '';

            return implode("\n", $lines);
        }

        $findings = $result->findings;
        usort($findings, static function (Finding $a, Finding $b): int {
            return [self::SEVERITY_ORDER[$a->severity] ?? 9, $a->file, $a->line]
                <=> [self::SEVERITY_ORDER[$b->severity] ?? 9, $b->file, $b->line];
        });

        $index = 1;
        foreach ($findings as $finding) {
            $lines[] = sprintf(
                '%s %s  %s',
                $this->paint('[' . strtoupper($finding->severity) . ']', $this->severityColor($finding->severity), $color),
                $this->paint($finding->title, '1', $color),
                $this->paint($finding->category, '36', $color),
            );
            $lines[] = sprintf('  %s:%d', $finding->file, $finding->line);
            $lines[] = '  sink: ' . $finding->sink;
            $lines[] = '  taint: ' . implode(' , ', $finding->taint);
            if ($finding->path !== []) {
                $lines[] = '  path: ' . implode(' && ', $finding->path);
            } else {
                $lines[] = '  path: (unconditional)';
            }
            if ($finding->payload !== '') {
                $lines[] = '  payload: ' . $finding->payload;
            }
            if ($finding->snippet !== '') {
                $lines[] = '  | ' . trim($finding->snippet);
            }
            $lines[] = '';
            $index++;
        }

        $lines[] = sprintf('%d finding(s).', count($findings));

        return implode("\n", $lines);
    }

    private function severityColor(string $severity): string
    {
        return match ($severity) {
            'critical' => '1;31',
            'high' => '31',
            'medium' => '33',
            default => '36',
        };
    }

    private function paint(string $text, string $code, bool $enabled): string
    {
        return $enabled ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
