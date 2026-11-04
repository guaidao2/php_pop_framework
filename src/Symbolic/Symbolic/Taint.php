<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Symbolic;

/**
 * 不可变的污点集合。
 *
 * 一个符号值可能同时来自多个输入（例如 $_GET['a'] . $_POST['b']），
 * 因此这里保存来源集合而不是单个标记。
 *
 * 除了来源之外，Taint 还记录"已针对哪些漏洞类别净化"：
 * htmlspecialchars() 只清除 XSS，addslashes() 只清除 SQLI。
 */
final class Taint
{
    /** @var array<string, TaintSource> */
    private array $sources;

    /** @var array<string, true> */
    private array $sanitizedFor;

    /**
     * @param array<string, TaintSource> $sources
     * @param array<string, true> $sanitizedFor
     */
    private function __construct(array $sources, array $sanitizedFor = [])
    {
        $this->sources = $sources;
        $this->sanitizedFor = $sanitizedFor;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(TaintSource $source): self
    {
        return new self([$source->id() => $source]);
    }

    /**
     * @param iterable<TaintSource> $sources
     */
    public static function ofMany(iterable $sources): self
    {
        $map = [];
        foreach ($sources as $source) {
            $map[$source->id()] = $source;
        }

        return new self($map);
    }

    public function union(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->isEmpty()) {
            return $other;
        }

        // 只有两边都针对某类别净化过，合并结果才算净化。
        return new self(
            $this->sources + $other->sources,
            array_intersect_key($this->sanitizedFor, $other->sanitizedFor),
        );
    }

    /**
     * 合并一组污点。
     *
     * @param iterable<Taint> $taints
     */
    public static function merge(iterable $taints): self
    {
        $result = self::none();
        foreach ($taints as $taint) {
            $result = $result->union($taint);
        }

        return $result;
    }

    /**
     * 移除指定类别的来源（按来源类别，而不是漏洞类别）。
     *
     * 类别使用 "ALL" 表示清除全部。
     *
     * @param list<string> $kinds
     */
    public function without(array $kinds): self
    {
        if ($this->isEmpty() || $kinds === []) {
            return $this;
        }
        if (in_array('ALL', $kinds, true)) {
            return self::none();
        }
        $set = array_flip($kinds);
        $remaining = array_filter(
            $this->sources,
            static fn (TaintSource $source): bool => !isset($set[$source->kind]),
        );

        return new self($remaining, $this->sanitizedFor);
    }

    /**
     * 标记该污点已经针对给定漏洞类别净化。
     *
     * @param list<string> $categories
     */
    public function sanitizeFor(array $categories): self
    {
        if ($this->isEmpty() || $categories === []) {
            return $this;
        }
        $sanitized = $this->sanitizedFor;
        foreach ($categories as $category) {
            $sanitized[$category] = true;
        }

        return new self($this->sources, $sanitized);
    }

    /**
     * 撤销针对某些漏洞类别的净化（例如 html_entity_decode 会把
     * htmlspecialchars 的结果还原成可执行 HTML）。
     *
     * @param list<string> $categories
     */
    public function unsanitizeFor(array $categories): self
    {
        if ($this->isEmpty() || $categories === [] || $this->sanitizedFor === []) {
            return $this;
        }
        $sanitized = $this->sanitizedFor;
        foreach ($categories as $category) {
            unset($sanitized[$category]);
        }

        return new self($this->sources, $sanitized);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    public function isTainted(): bool
    {
        return $this->sources !== [];
    }

    /**
     * 对指定漏洞类别是否仍有污点。
     */
    public function isTaintedFor(string $category): bool
    {
        if ($this->sources === []) {
            return false;
        }

        return !isset($this->sanitizedFor['ALL']) && !isset($this->sanitizedFor[$category]);
    }

    /**
     * @return list<TaintSource>
     */
    public function sources(): array
    {
        return array_values($this->sources);
    }

    /**
     * @return list<string>
     */
    public function describe(): array
    {
        return array_map(
            static fn (TaintSource $source): string => $source->describe(),
            $this->sources(),
        );
    }

    public function describeInline(): string
    {
        return implode(' , ', $this->describe());
    }

    /**
     * 是否包含指定类别的污点（按来源类别）。
     */
    public function hasKind(string $kind): bool
    {
        foreach ($this->sources as $source) {
            if ($source->kind === $kind) {
                return true;
            }
        }

        return false;
    }
}
