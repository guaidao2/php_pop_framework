<?php
declare(strict_types=1);

namespace PopChain\Engine;

/**
 * 引擎注册表。
 *
 * 主路径是"进程内引擎"：引擎代码直接合并进本仓库（建议放 src/Symbolic/，
 * 命名空间 PopChain\Symbolic），实现 EngineInterface 后在这里登记一个名字，
 * CLI 用 --engine=<名字> 选择。外部进程引擎（ExternalEngine）只是备选，
 * 方便把别的语言/独立工具临时挂进来。
 */
final class EngineRegistry
{
    /** @var array<string, string> 名字 => EngineInterface 实现类 */
    private static array $engines = [];

    /** @var array<string, string> 需要额外参数的引擎名 */
    private const EXTERNAL = [
        'external' => ExternalEngine::class,
    ];

    public static function register(string $name, string $class): void
    {
        self::$engines[strtolower($name)] = $class;
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = ['none'];
        if (class_exists('PopChain\\Symbolic\\Engine')) {
            $names[] = 'builtin';
        }
        $names[] = 'external';
        foreach (array_keys(self::$engines) as $registered) {
            if (!in_array($registered, $names, true)) {
                $names[] = $registered;
            }
        }
        return $names;
    }

    /**
     * 创建引擎实例；不可用时返回 NullEngine（调用方继续走启发式）。
     *
     * @param array<string, mixed> $options engine-cmd / engine-timeout 等
     */
    public static function create(string $name, array $options = []): EngineInterface
    {
        $key = strtolower($name);

        if ($key === '' || $key === 'none') {
            return new NullEngine();
        }

        if (isset(self::EXTERNAL[$key])) {
            $command = (string) ($options['engine-cmd'] ?? '');
            if ($command === '') {
                return new NullEngine();
            }
            return new ExternalEngine($command, (float) ($options['engine-timeout'] ?? 10));
        }

        if ($key === 'builtin' && class_exists('PopChain\\Symbolic\\Engine')) {
            $class = 'PopChain\\Symbolic\\Engine';
            /** @var EngineInterface $engine */
            $engine = new $class();
            return $engine;
        }

        $class = self::$engines[$key] ?? null;
        if ($class !== null && class_exists($class)) {
            /** @var EngineInterface $engine */
            $engine = new $class();
            return $engine;
        }

        return new NullEngine();
    }
}
