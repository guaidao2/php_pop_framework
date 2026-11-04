<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Report;

use PopChain\Symbolic\Engine\AnalysisResult;

/**
 * JSON 报告（便于集成到 CI / 其他工具）。
 */
final class JsonReporter
{
    public function render(AnalysisResult $result): string
    {
        return (string) json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
