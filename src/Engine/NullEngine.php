<?php
declare(strict_types=1);

namespace PopChain\Engine;

/**
 * 没有配置引擎时的占位实现：永远不可用，调用方自动走启发式。
 */
final class NullEngine implements EngineInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function version(): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function analyze(array $request): ?array
    {
        return null;
    }
}
