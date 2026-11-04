<?php
declare(strict_types=1);

namespace PopChain\Detector;

use PopChain\Model\Chain;

/**
 * 一次检测结果。
 *
 * 反序列化链检测器返回带 chain 的 Finding；
 * 符号执行等外部检测器返回不带 chain、带文件/行号/污点信息的 Finding。
 */
final class Finding
{
    /** @param list<string> $notes */
    public function __construct(
        public string $type,
        public string $title,
        public ?Chain $chain = null,
        public array $notes = [],
        public bool $feasible = true,
        public string $severity = 'high',
        public string $category = '',
        public string $file = '',
        public int $line = 0,
        public string $sink = '',
        /** @var list<string> */
        public array $taint = [],
        /** @var list<string> */
        public array $path = [],
        public string $payload = '',
        public string $snippet = '',
    ) {
    }

    /** @return list<string> */
    public function nodes(): array
    {
        return $this->chain?->nodes() ?? [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'file' => $this->file,
            'line' => $this->line,
            'sink' => $this->sink,
            'taint' => $this->taint,
            'path' => $this->path,
            'payload' => $this->payload,
            'snippet' => $this->snippet,
            'notes' => $this->notes,
            'feasible' => $this->feasible,
        ];
    }
}
