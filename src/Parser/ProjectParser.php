<?php
declare(strict_types=1);

namespace PopChain\Parser;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PopChain\Model\ClassInfo;
use PopChain\Model\MethodInfo;
use PopChain\Model\ParamInfo;
use PopChain\Model\PropertyInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 把 PHP 源码目录解析成统一的类信息 IR。
 */
final class ProjectParser
{
    private Parser $parser;
    private Standard $printer;

    /** @var array<string, ClassInfo> */
    private array $classes = [];

    /** @var list<string> */
    private array $warnings = [];

    private bool $includeVendor;

    public function __construct(bool $includeVendor = false)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
        $this->includeVendor = $includeVendor;
    }

    /** @return array<string, ClassInfo> */
    public function parsePath(string $path): array
    {
        $this->classes = [];
        $this->warnings = [];

        $files = $this->phpFiles($path);
        if ($files === []) {
            $this->warnings[] = "No PHP files found: {$path}";
        }
        foreach ($files as $file) {
            $this->parseFile($file);
        }
        $this->resolveTraits();
        return $this->classes;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            $real = realpath($path);
            return $real === false ? [] : [$real];
        }
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false) {
                continue;
            }
            if (!$this->includeVendor) {
                $normalized = str_replace('\\', '/', $real);
                if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/.git/')) {
                    continue;
                }
            }
            $files[] = $real;
        }
        sort($files);
        return $files;
    }

    private function parseFile(string $file): void
    {
        $code = file_get_contents($file);
        if ($code === false) {
            $this->warnings[] = "Cannot read: {$file}";
            return;
        }

        try {
            $stmts = $this->parser->parse($code);
        } catch (Error $e) {
            $this->warnings[] = sprintf('%s: %s', $file, $e->getMessage());
            return;
        }
        if ($stmts === null) {
            return;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $node) {
            $class = $this->buildClass($node, $file);
            if ($class === null) {
                continue;
            }
            if (isset($this->classes[$class->name])) {
                $this->warnings[] = sprintf(
                    'Duplicate class %s (overridden by %s)',
                    $class->name,
                    $file
                );
            }
            $this->classes[$class->name] = $class;
        }
    }

    private function buildClass(Stmt\ClassLike $node, string $file): ?ClassInfo
    {
        $anonymous = $node->name === null;
        $name = $anonymous
            ? sprintf('class@anonymous:%s:%d', basename($file), $node->getStartLine())
            : ($node->namespacedName?->toString() ?? $node->name->toString());

        $parent = $node instanceof Stmt\Class_ && $node->extends !== null
            ? ltrim($node->extends->toString(), '\\')
            : null;

        $interfaces = [];
        if (property_exists($node, 'implements')) {
            foreach ($node->implements as $interface) {
                $interfaces[] = ltrim($interface->toString(), '\\');
            }
        }

        $traits = [];
        $traitAliases = [];
        $traitPrecedence = [];
        $properties = [];
        $methods = [];

        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = ltrim($trait->toString(), '\\');
                }
                foreach ($stmt->adaptations as $adaptation) {
                    if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                        $traitAliases[] = [
                            'trait' => $adaptation->trait !== null
                                ? ltrim($adaptation->trait->toString(), '\\')
                                : null,
                            'method' => $adaptation->method->toString(),
                            'newName' => $adaptation->newName?->toString(),
                            'visibility' => $this->visibilityFromModifier($adaptation->newModifier),
                        ];
                    } elseif ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                        $traitPrecedence[] = [
                            'trait' => $adaptation->trait !== null
                                ? ltrim($adaptation->trait->toString(), '\\')
                                : null,
                            'method' => $adaptation->method->toString(),
                            'insteadof' => array_map(
                                static fn (Name $name): string => ltrim($name->toString(), '\\'),
                                $adaptation->insteadof,
                            ),
                        ];
                    }
                }
            } elseif ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $properties[] = new PropertyInfo(
                        name: $prop->name->toString(),
                        visibility: $this->visibility($stmt),
                        static: $stmt->isStatic(),
                        defaultRaw: $prop->default !== null
                            ? $this->printer->prettyPrintExpr($prop->default)
                            : null,
                        declaringClass: $name,
                    );
                }
            } elseif ($stmt instanceof Stmt\ClassMethod) {
                $method = $this->buildMethod($stmt, $name);
                $methods[] = $method;

                if (strcasecmp($method->name, '__construct') === 0) {
                    foreach ($method->params as $param) {
                        if (!$param->promoted) {
                            continue;
                        }
                        $properties[] = new PropertyInfo(
                            name: $param->name,
                            visibility: $param->visibility,
                            static: false,
                            defaultRaw: $param->defaultRaw,
                            promoted: true,
                            declaringClass: $name,
                        );
                    }
                }
            }
        }

        return new ClassInfo(
            name: $name,
            parent: $parent,
            interfaces: $interfaces,
            traits: $traits,
            properties: $properties,
            methods: $methods,
            file: $file,
            line: $node->getStartLine(),
            abstract: $node instanceof Stmt\Class_ && $node->isAbstract(),
            anonymous: $anonymous,
            trait: $node instanceof Stmt\Trait_,
            interface: $node instanceof Stmt\Interface_,
            enum: $node instanceof Stmt\Enum_,
            traitAliases: $traitAliases,
            traitPrecedence: $traitPrecedence,
        );
    }

    private function buildMethod(Stmt\ClassMethod $node, string $declaringClass): MethodInfo
    {
        $params = [];
        foreach ($node->params as $position => $param) {
            $params[] = new ParamInfo(
                name: is_string($param->var->name) ? $param->var->name : '?',
                position: $position,
                defaultRaw: $param->default !== null
                    ? $this->printer->prettyPrintExpr($param->default)
                    : null,
                type: $param->type !== null ? $this->typeToString($param->type) : null,
                promoted: $param->isPromoted(),
                visibility: $param->isPublic() ? 'public' : ($param->isProtected() ? 'protected' : 'private'),
            );
        }

        $calls = [];
        $stringUses = [];
        $collector = null;
        if ($node->stmts !== null) {
            $traverser = new NodeTraverser();
            $paramNames = [];
            foreach ($node->params as $param) {
                if (is_string($param->var->name)) {
                    $paramNames[] = $param->var->name;
                }
            }
            $collector = new CallCollector($paramNames, $node->name->toString());
            $traverser->addVisitor($collector);
            $traverser->traverse($node->stmts);
            $calls = $collector->calls;
            $stringUses = $collector->stringUses;
        }

        $name = $node->name->toString();
        return new MethodInfo(
            name: $name,
            visibility: $this->visibility($node),
            static: $node->isStatic(),
            params: $params,
            calls: $calls,
            line: $node->getStartLine(),
            magic: str_starts_with($name, '__'),
            declaringClass: $declaringClass,
            stringUses: $stringUses,
            constantProperties: $collector?->constantProperties() ?? [],
        );
    }

    /** trait 适配里的修饰符（0 表示没写可见性）。 */
    private function visibilityFromModifier(?int $modifier): ?string
    {
        if ($modifier === null) {
            return null;
        }
        if (($modifier & Stmt\Class_::MODIFIER_PRIVATE) !== 0) {
            return 'private';
        }
        if (($modifier & Stmt\Class_::MODIFIER_PROTECTED) !== 0) {
            return 'protected';
        }
        if (($modifier & Stmt\Class_::MODIFIER_PUBLIC) !== 0) {
            return 'public';
        }
        return null;
    }

    private function visibility(Node $node): string
    {
        if (method_exists($node, 'isPrivate') && $node->isPrivate()) {
            return 'private';
        }
        if (method_exists($node, 'isProtected') && $node->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    private function typeToString(Node $type): string
    {
        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn (Node $t): string => $this->typeToString($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn (Node $t): string => $this->typeToString($t), $type->types));
        }
        return 'mixed';
    }

    /**
     * 把 trait 的方法与属性压平进使用它的类。
     *
     * PHP 在编译期把 trait 成员复制进类，因此 trait 里的 private 属性在序列化时
     * 用的是使用类名（declaringClass 必须改写）；类自己的同名成员优先。
     */
    private function resolveTraits(): void
    {
        foreach ($this->classes as $class) {
            if ($class->traits === []) {
                continue;
            }
            $methods = $class->methods;
            $properties = $class->properties;
            $seen = [];
            $copied = [];
            $this->mergeTraitMembers($class, $class, $methods, $properties, $seen, $copied);
            $this->applyTraitAliases($class, $methods, $copied);
            $class->methods = $methods;
            $class->properties = $properties;
        }
    }

    /**
     * @param list<MethodInfo> $methods
     * @param list<PropertyInfo> $properties
     * @param array<string, true> $seen
     */
    private function mergeTraitMembers(
        ClassInfo $class,
        ClassInfo $owner,
        array &$methods,
        array &$properties,
        array &$seen,
        array &$copied = [],
    ): void {
        $excluded = $this->excludedTraitMethods($owner);

        foreach ($class->traits as $traitName) {
            if (isset($seen[$traitName])) {
                continue;
            }
            $seen[$traitName] = true;

            $trait = $this->classes[$traitName] ?? null;
            if ($trait === null) {
                $this->warnings[] = sprintf('Trait %s not found (used by %s)', $traitName, $owner->name);
                continue;
            }

            // trait 里再 use 其它 trait
            if ($trait->traits !== []) {
                $this->mergeTraitMembers($trait, $owner, $methods, $properties, $seen, $copied);
            }

            foreach ($trait->methods as $method) {
                if ($this->hasMethodNamed($methods, $method->name)) {
                    continue;
                }
                if (isset($excluded[strtolower($traitName)][strtolower($method->name)])) {
                    // insteadof：这个方法被同名的另一个 trait 覆盖
                    continue;
                }
                $copied[] = ['trait' => $traitName, 'name' => $method->name, 'index' => count($methods)];
                $methods[] = new MethodInfo(
                    name: $method->name,
                    visibility: $method->visibility,
                    static: $method->static,
                    params: $method->params,
                    calls: $method->calls,
                    line: $method->line,
                    magic: $method->magic,
                    declaringClass: $owner->name,
                    stringUses: $method->stringUses,
                );
            }

            foreach ($trait->properties as $property) {
                if ($this->hasPropertyNamed($properties, $property->name)) {
                    continue;
                }
                $properties[] = new PropertyInfo(
                    name: $property->name,
                    visibility: $property->visibility,
                    static: $property->static,
                    defaultRaw: $property->defaultRaw,
                    promoted: $property->promoted,
                    declaringClass: $owner->name,
                );
            }
        }
    }

    /** @param list<MethodInfo> $methods */
    private function hasMethodNamed(array $methods, string $name): bool
    {
        foreach ($methods as $method) {
            if (strcasecmp($method->name, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<PropertyInfo> $properties */
    private function hasPropertyNamed(array $properties, string $name): bool
    {
        foreach ($properties as $property) {
            if ($property->name === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * insteadof 排除表：trait 名 + 方法名 => true。
     *
     * @return array<string, array<string, true>>
     */
    private function excludedTraitMethods(ClassInfo $owner): array
    {
        $excluded = [];
        foreach ($owner->traitPrecedence as $rule) {
            $method = strtolower((string) $rule['method']);
            foreach ($rule['insteadof'] as $loser) {
                $excluded[strtolower($loser)][$method] = true;
            }
        }
        return $excluded;
    }

    /**
     * 别名指向的方法可能因为 insteadof 没被复制进类，这里回 trait 定义里取。
     *
     * @param array{trait:?string,method:string} $alias
     */
    private function aliasSourceMethod(ClassInfo $owner, array $alias): ?MethodInfo
    {
        $traitName = $alias['trait'];
        if ($traitName !== null) {
            $trait = $this->classes[$traitName] ?? null;
            $method = $trait?->method($alias['method']);
            return $method === null ? null : $this->methodOwnedBy($method, $owner);
        }

        foreach ($owner->traits as $used) {
            $method = ($this->classes[$used] ?? null)?->method($alias['method']);
            if ($method !== null) {
                return $this->methodOwnedBy($method, $owner);
            }
        }
        return null;
    }

    /** 复制一份方法信息，声明类改成使用该 trait 的类。 */
    private function methodOwnedBy(MethodInfo $method, ClassInfo $owner): MethodInfo
    {
        return new MethodInfo(
            name: $method->name,
            visibility: $method->visibility,
            static: $method->static,
            params: $method->params,
            calls: $method->calls,
            line: $method->line,
            magic: $method->magic,
            declaringClass: $owner->name,
            stringUses: $method->stringUses,
        );
    }

    /**
     * 应用 trait 别名（as）：改名复制一份，或只改可见性。
     *
     * @param list<MethodInfo> $methods
     * @param list<array{trait:string,name:string,index:int}> $copied
     */
    private function applyTraitAliases(ClassInfo $owner, array &$methods, array $copied): void
    {
        foreach ($owner->traitAliases as $alias) {
            $target = null;
            foreach ($copied as $entry) {
                if (strcasecmp($entry['name'], $alias['method']) !== 0) {
                    continue;
                }
                if ($alias['trait'] !== null && strcasecmp($entry['trait'], (string) $alias['trait']) !== 0) {
                    continue;
                }
                $target = $entry['index'];
                break;
            }

            $source = $target !== null && isset($methods[$target])
                ? $methods[$target]
                : $this->aliasSourceMethod($owner, $alias);

            if ($source === null) {
                continue;
            }

            $visibility = $alias['visibility'] ?? $source->visibility;

            if ($alias['newName'] === null) {
                $methods[$target] = new MethodInfo(
                    name: $source->name,
                    visibility: $visibility,
                    static: $source->static,
                    params: $source->params,
                    calls: $source->calls,
                    line: $source->line,
                    magic: $source->magic,
                    declaringClass: $source->declaringClass,
                    stringUses: $source->stringUses,
                );
                continue;
            }

            $newName = (string) $alias['newName'];
            if ($this->hasMethodNamed($methods, $newName)) {
                continue;
            }
            $methods[] = new MethodInfo(
                name: $newName,
                visibility: $visibility,
                static: $source->static,
                params: $source->params,
                calls: $source->calls,
                line: $source->line,
                magic: str_starts_with($newName, '__'),
                declaringClass: $source->declaringClass,
                stringUses: $source->stringUses,
            );
        }
    }
}
