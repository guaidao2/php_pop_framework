<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

/**
 * 分析参数（路径爆炸与时间预算控制）。
 */
final class AnalyzerOptions
{
    public function __construct(
        public readonly int $maxStates = 64,
        public readonly int $maxCallDepth = 8,
        public readonly int $maxLoopIterations = 3,
        public readonly int $maxPaths = 500,
        public readonly float $timeout = 30.0,
        public readonly bool $followIncludes = true,
        public readonly bool $argvAsSource = true,
        public readonly bool $includeSafePaths = false,
        /** @var list<string> */
        public readonly array $excludeDirs = ['vendor', 'node_modules', '.git', '.idea'],
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            maxStates: (int) ($options['maxStates'] ?? 64),
            maxCallDepth: (int) ($options['maxCallDepth'] ?? 8),
            maxLoopIterations: (int) ($options['maxLoopIterations'] ?? 3),
            maxPaths: (int) ($options['maxPaths'] ?? 500),
            timeout: (float) ($options['timeout'] ?? 30.0),
            followIncludes: (bool) ($options['followIncludes'] ?? true),
            argvAsSource: (bool) ($options['argvAsSource'] ?? true),
            excludeDirs: $options['excludeDirs'] ?? ['vendor', 'node_modules', '.git', '.idea'],
        );
    }
}
