<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Security;

/**
 * 一条漏洞发现。
 */
final class Finding
{
    /**
     * @param list<string> $taint
     * @param list<string> $path
     */
    public function __construct(
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $file,
        public readonly int $line,
        public readonly string $sink,
        public readonly array $taint,
        public readonly array $path,
        public readonly string $payload,
        public readonly string $snippet = '',
    ) {
    }

    public function id(): string
    {
        return sha1(implode('|', [
            $this->file,
            (string) $this->line,
            $this->category,
            $this->sink,
            implode(',', $this->taint),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'file' => $this->file,
            'line' => $this->line,
            'sink' => $this->sink,
            'taint' => $this->taint,
            'path' => $this->path,
            'payload' => $this->payload,
            'snippet' => $this->snippet,
        ];
    }
}
