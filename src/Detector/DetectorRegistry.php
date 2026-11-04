<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Engine\ChainConstraintSolver;
use PopChain\Engine\EngineInterface;

/**
 * 检测器注册表。新增漏洞类型时在 DETECTORS 里登记实现类即可，
 * CLI 的 --type / --list-types 会自动带上。
 */
final class DetectorRegistry
{
    /** @var array<string, class-string<DetectorInterface>> */
    private const DETECTORS = [
        'deserialization' => DeserializationDetector::class,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::DETECTORS);
    }

    public static function create(string $name, ?EngineInterface $engine = null): ?DetectorInterface
    {
        $class = self::DETECTORS[strtolower($name)] ?? null;
        if ($class === null) {
            return null;
        }

        $solver = $engine === null ? null : new ChainConstraintSolver($engine);

        return new $class($solver);
    }

    /** @return array<string, string> 类型名 => 说明 */
    public static function describe(): array
    {
        $out = [];
        foreach (self::names() as $name) {
            $detector = self::create($name);
            if ($detector !== null) {
                $out[$name] = $detector->description();
            }
        }
        return $out;
    }
}
