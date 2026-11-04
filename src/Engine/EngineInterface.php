<?php
declare(strict_types=1);

namespace PopChain\Engine;

/**
 * 符号执行/约束求解后端的统一接口。
 *
 * 实现可以是独立进程（ExternalEngine），也可以是将来接进进程内的库。
 * 引擎不可用或超时时，调用方退回现有的启发式分析，不影响主流程。
 */
interface EngineInterface
{
    public function name(): string;

    public function version(): string;

    public function isAvailable(): bool;

    /**
     * 发送一次分析请求，返回引擎的响应；失败返回 null。
     *
     * 请求/响应的字段定义见 docs/symbolic-engine-protocol.md。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    public function analyze(array $request): ?array;
}
