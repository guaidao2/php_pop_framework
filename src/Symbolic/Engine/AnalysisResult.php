<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Security\Finding;

/**
 * 一次分析的结果。
 */
final class AnalysisResult
{
    /**
     * @param list<Finding> $findings
     * @param array{checks: int, pruned: int, unknown: int} $solverStats
     * @param list<string> $parseErrors
     */
    public function __construct(
        public readonly array $findings,
        public readonly int $fileCount,
        public readonly int $pathCount,
        public readonly array $solverStats,
        public readonly bool $truncated,
        public readonly float $duration,
        public readonly int $statementCount = 0,
        public readonly array $parseErrors = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'stats' => [
                'files' => $this->fileCount,
                'paths' => $this->pathCount,
                'statements' => $this->statementCount,
                'duration' => round($this->duration, 3),
                'truncated' => $this->truncated,
                'solver' => $this->solverStats,
            ],
            'parseErrors' => $this->parseErrors,
        ];
    }
}
