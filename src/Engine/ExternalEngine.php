<?php
declare(strict_types=1);

namespace PopChain\Engine;

/**
 * 通过命令行把请求交给外部引擎进程：
 *   - 请求 JSON 从 stdin 写入；
 *   - 引擎把响应 JSON 写到 stdout；
 *   - 超时、进程异常、输出不是合法 JSON 都按"不可用"处理。
 */
final class ExternalEngine implements EngineInterface
{
    public function __construct(
        private string $command,
        private float $timeoutSeconds = 10.0,
    ) {
    }

    public function name(): string
    {
        return 'external';
    }

    public function version(): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return trim($this->command) !== '';
    }

    public function analyze(array $request): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $payload = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > $this->timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            return null;
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
