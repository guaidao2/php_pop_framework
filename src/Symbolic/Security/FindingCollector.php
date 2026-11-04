<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

/**
 * 漏洞收集器（按 file:line:category:sink:taint 去重）。
 */
final class FindingCollector
{
    /** @var array<string, Finding> */
    private array $findings = [];

    public function add(Finding $finding): void
    {
        $id = $finding->id();
        if (!isset($this->findings[$id])) {
            $this->findings[$id] = $finding;
        }
    }

    /**
     * @return list<Finding>
     */
    public function all(): array
    {
        $findings = array_values($this->findings);
        usort($findings, static function (Finding $a, Finding $b): int {
            return [$a->file, $a->line, $a->category] <=> [$b->file, $b->line, $b->category];
        });

        return $findings;
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function merge(self $other): void
    {
        foreach ($other->findings as $finding) {
            $this->add($finding);
        }
    }
}
