<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * 程序索引：跨文件收集函数、类、顶层语句与 use 别名。
 */
final class Program
{
    /** @var array<string, FunctionDef> */
    private array $functions = [];

    /** @var array<string, ClassDef> */
    private array $classes = [];

    /** @var array<string, list<Node>> */
    private array $fileStatements = [];

    /** @var array<string, array<string, string>> */
    private array $fileUses = [];

    /** @var array<string, string|null> */
    private array $fileNamespaces = [];

    /**
     * @param list<Node> $stmts
     */
    public function addFile(string $path, array $stmts): void
    {
        $this->fileStatements[$path] = $stmts;

        $uses = [];
        $namespace = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $namespace ??= $stmt->name?->toString();
                foreach ($stmt->stmts as $inner) {
                    $this->collectUses($inner, $uses);
                }
            } else {
                $this->collectUses($stmt, $uses);
            }
        }
        $this->fileUses[$path] = $uses;
        $this->fileNamespaces[$path] = $namespace;

        $this->scan($stmts, $path, null);
    }

    /**
     * @return list<Node>|null
     */
    public function statements(string $path): ?array
    {
        return $this->fileStatements[$path] ?? null;
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_keys($this->fileStatements);
    }

    /**
     * @return array<string, string>
     */
    public function fileUses(string $path): array
    {
        return $this->fileUses[$path] ?? [];
    }

    public function fileNamespace(string $path): ?string
    {
        return $this->fileNamespaces[$path] ?? null;
    }

    /**
     * @param array<string, string> $uses
     */
    public function resolveFunction(string $name, ?string $namespace = null, array $uses = []): ?FunctionDef
    {
        $resolved = $this->resolveName($name, $namespace, $uses);

        return $this->functions[strtolower($resolved)]
            ?? $this->functions[strtolower($name)]
            ?? null;
    }

    /**
     * @param array<string, string> $uses
     */
    public function resolveClass(string $name, ?string $namespace = null, array $uses = []): ?ClassDef
    {
        $resolved = $this->resolveName($name, $namespace, $uses);

        return $this->classes[strtolower($resolved)]
            ?? $this->classes[strtolower($name)]
            ?? null;
    }

    /**
     * @param array<string, string> $uses
     */
    private function resolveName(string $name, ?string $namespace, array $uses): string
    {
        $trimmed = ltrim($name, '\\');
        $parts = explode('\\', $trimmed);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            $parts[0] = $uses[$first];
            $trimmed = implode('\\', $parts);

            return $trimmed;
        }

        if ($namespace !== null && !str_contains($trimmed, '\\')) {
            return $namespace . '\\' . $trimmed;
        }

        return $trimmed;
    }

    // ------------------------------------------------------------------
    // 索引
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $uses
     */
    private function collectUses(Node $node, array &$uses): void
    {
        if ($node instanceof Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->getAlias()->toString();
                $uses[strtolower($alias)] = $use->name->toString();
            }
        } elseif ($node instanceof Stmt\GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $alias = $use->getAlias()->toString();
                $uses[strtolower($alias)] = $prefix . '\\' . $use->name->toString();
            }
        }
    }

    /**
     * @param list<Node> $nodes
     */
    private function scan(array $nodes, string $file, ?string $namespace): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $this->scan($node->stmts, $file, $node->name?->toString());
                continue;
            }

            if ($node instanceof Stmt\Function_) {
                $this->registerFunction($node, $file, $namespace);
            }

            if ($node instanceof Stmt\ClassLike) {
                $this->registerClass($node, $file, $namespace);
                continue;
            }

            foreach ($node->getSubNodeNames() as $subName) {
                $child = $node->{$subName};
                if ($child instanceof Node) {
                    $this->scan([$child], $file, $namespace);
                } elseif (is_array($child)) {
                    $this->scan($child, $file, $namespace);
                }
            }
        }
    }

    private function registerFunction(Stmt\Function_ $node, string $file, ?string $namespace): void
    {
        $short = $node->name->toString();
        $full = $namespace !== null ? $namespace . '\\' . $short : $short;
        $definition = new FunctionDef(
            $full,
            $node->params,
            $node->stmts ?? [],
            $file,
            $node->getStartLine(),
            $namespace,
        );

        $this->functions[strtolower($full)] = $definition;
        $this->functions[strtolower($short)] ??= $definition;
    }

    private function registerClass(Stmt\ClassLike $node, string $file, ?string $namespace): void
    {
        if ($node->name === null) {
            return;
        }

        $short = $node->name->toString();
        $full = $namespace !== null ? $namespace . '\\' . $short : $short;
        $parent = null;
        if ($node instanceof Stmt\Class_ && $node->extends !== null) {
            $parent = $node->extends->toString();
        }

        $methods = [];
        $traits = [];
        foreach ($node->stmts as $member) {
            if ($member instanceof Stmt\TraitUse) {
                foreach ($member->traits as $trait) {
                    $traits[] = $trait->toString();
                }
                continue;
            }
            if (!$member instanceof Stmt\ClassMethod) {
                continue;
            }
            $def = new FunctionDef(
                $full . '::' . $member->name->toString(),
                $member->params,
                $member->stmts ?? [],
                $file,
                $member->getStartLine(),
                $namespace,
                null,
            );
            $methods[strtolower($member->name->toString())] = $def;
        }

        $definition = new ClassDef($full, $parent, $methods, $file, $node->getStartLine(), $traits);
        // 回填 class 引用（FunctionDef 是 readonly，重新构造）
        $withClass = [];
        foreach ($methods as $key => $method) {
            $withClass[$key] = new FunctionDef(
                $method->name,
                $method->params,
                $method->stmts,
                $method->file,
                $method->line,
                $method->namespace,
                $definition,
            );
        }
        $definition = new ClassDef($full, $parent, $withClass, $file, $node->getStartLine(), $traits);

        $this->classes[strtolower($full)] = $definition;
        $this->classes[strtolower($short)] ??= $definition;
    }
}
