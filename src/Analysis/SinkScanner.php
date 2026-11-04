<?php
declare(strict_types=1);

namespace PopChain\Analysis;

use PopChain\Model\ArgInfo;
use PopChain\Model\CallSite;
use PopChain\Model\MethodInfo;
use PopChain\Model\SinkCatalog;
use PopChain\Model\SinkHit;

/**
 * 危险函数（sink）识别。函数目录见 Model\SinkCatalog。
 */
final class SinkScanner
{
    public function isSinkFunction(string $name): bool
    {
        return SinkCatalog::match($name) !== null;
    }

    /** @return list<SinkHit> */
    public function hitsIn(MethodInfo $method): array
    {
        $hits = [];
        foreach ($method->calls as $call) {
            foreach ($this->match($call) as $hit) {
                $hits[] = $hit;
            }
        }
        return $hits;
    }

    /** @return list<SinkHit> */
    public function match(CallSite $call): array
    {
        if ($call->kind !== CallSite::FUNCTION_CALL || $call->target === null) {
            return [];
        }
        $sink = SinkCatalog::match($call->target);
        if ($sink === null) {
            return [];
        }
        [$positions, $category] = $sink;

        $short = strtolower($this->shortName($call->target));
        $hits = [];
        foreach ($positions as $position) {
            $arg = $call->args[$position] ?? null;
            if ($arg === null) {
                continue;
            }
            $hits[] = new SinkHit($short, $category, $position, $arg, $call->line);
        }
        return $hits;
    }

    /**
     * sink 参数在方法内部是否可控（属性、拼接、或方法形参）。
     *
     * 形参是否真的可控还需要 ChainFinder 结合调用点的实参映射判断，
     * 这里只做方法内的局部判断。
     */
    public function isControllable(SinkHit $hit, MethodInfo $method): bool
    {
        if ($hit->arg->isControllable()) {
            return true;
        }
        if ($hit->arg->kind === ArgInfo::VARIABLE && $hit->arg->name !== null) {
            return $method->paramNamed($hit->arg->name) !== null;
        }
        return false;
    }

    private function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
