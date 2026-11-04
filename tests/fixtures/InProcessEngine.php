<?php
declare(strict_types=1);

namespace TestFixture;

use PopChain\Engine\EngineInterface;

/**
 * 测试用进程内引擎：模拟"引擎包已合并进仓库"的形态。
 */
final class FakeSymbolicEngine implements EngineInterface
{
    public function name(): string
    {
        return 'fake-inproc';
    }

    public function version(): string
    {
        return '0.1';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function analyze(array $request): ?array
    {
        $entry = is_array($request['entry'] ?? null) ? $request['entry'] : [];
        return [
            'protocol_version' => '1.0',
            'status' => 'sat',
            'assignments' => [
                ['class' => (string) ($entry['class'] ?? ''), 'property' => 'inproc_marker', 'value' => 'yes'],
            ],
            'notes' => ['fake in-process engine'],
        ];
    }
}
