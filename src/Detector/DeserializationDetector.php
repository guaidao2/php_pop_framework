<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Analysis\ChainFinder;
use PopChain\Analysis\SinkScanner;
use PopChain\Engine\ChainConstraintSolver;
use PopChain\Engine\NullEngine;
use PopChain\Model\Chain;
use PopChain\Model\ClassInfo;

/**
 * 反序列化 POP 链检测器：现有 ChainFinder 的插件化包装。
 *
 * 如果配置了符号执行引擎，会把每条链的 CFG 和约束发过去：
 *   - sat：把引擎给出的属性赋值合并进 payload；
 *   - unsat：链标记为不可行，不输出 payload；
 *   - 引擎不可用/超时：只加一条提示，继续用启发式结果。
 */
final class DeserializationDetector implements DetectorInterface
{
    private SinkScanner $scanner;

    public function __construct(private ?ChainConstraintSolver $solver = null)
    {
        $this->scanner = new SinkScanner();
    }

    public function name(): string
    {
        return 'deserialization';
    }

    public function description(): string
    {
        return 'PHP object injection / POP chain detection (current default)';
    }

    /** @return list<Finding> */
    public function detect(array $classes, array $options = []): array
    {
        $entries = $options['entries'] ?? ['__destruct', '__wakeup', '__unserialize'];
        $maxDepth = (int) ($options['max_depth'] ?? 8);
        $maxChains = (int) ($options['max_chains'] ?? 50);

        $finder = new ChainFinder($classes, $this->scanner);
        $chains = $finder->find($entries, $maxDepth, $maxChains);

        $findings = [];
        foreach ($chains as $chain) {
            $findings[] = $this->buildFinding($chain, $classes);
        }
        return $findings;
    }

    /** @param array<string, ClassInfo> $classes */
    private function buildFinding(Chain $chain, array $classes): Finding
    {
        $notes = [];
        $feasible = true;

        if ($this->solver !== null && $this->solver->engine()->isAvailable()) {
            $result = $this->solver->solve($chain, $classes);
            foreach ($result['notes'] as $note) {
                $notes[] = $note;
            }

            if ($result['status'] === 'sat') {
                $chain->engineAssignments = $result['assignments'];
                if ($result['assignments'] !== []) {
                    $notes[] = sprintf(
                        'engine: sat (%d assignment%s)',
                        count($result['assignments']),
                        count($result['assignments']) === 1 ? '' : 's',
                    );
                }
            } elseif ($result['status'] === 'unsat') {
                $feasible = false;
                $notes[] = 'engine: unsat (path constraints cannot be satisfied)';
            }
        }

        return new Finding(
            type: $this->name(),
            title: $chain->entryClass->name . '::' . $chain->entryMethod->name,
            chain: $chain,
            notes: $notes,
            feasible: $feasible,
        );
    }
}
