<?php
declare(strict_types=1);

namespace PopChain\Analysis;

use PopChain\Model\ArgInfo;
use PopChain\Model\CallSite;
use PopChain\Model\Chain;
use PopChain\Model\ClassInfo;
use PopChain\Model\Edge;
use PopChain\Model\MethodInfo;

/**
 * 从魔术方法入口出发，沿可控属性正向搜索到 sink。
 *
 * 搜索规则：
 *   ($this->p)(...)              → 任意类的 __invoke
 *   call_user_func($this->p, ..) → 任意类的 __invoke
 *   $this->p->m(...)             → 任意类的方法 m（无则 __call）
 *   $this->p 处于字符串上下文     → 任意类的 __toString
 */
final class ChainFinder
{
    /**
     * 回调类 sink：函数名 => [可调用参数位置, 传参方式, 数组位置, 元素落在第几个形参]。
     *
     *   forward    call_user_func：剩余实参按位置传给被调用者
     *   args-array call_user_func_array：参数数组的第 k 个元素 → 第 k 个形参
     *   element    array_map / array_filter / array_walk / array_reduce：数组元素 → 指定形参
     *   none       usort 等：元素由引擎决定，无法静态映射
     */
    private const CALLABLE_SINKS = [
        'call_user_func' => [0, 'forward', null, 0],
        'call_user_func_array' => [0, 'args-array', 1, 0],
        'array_map' => [0, 'element', 1, 0],
        'array_filter' => [1, 'element', 0, 0],
        'array_walk' => [1, 'element', 0, 0],
        'array_reduce' => [1, 'element', 0, 1],
        'usort' => [1, 'none', null, 0],
        'uasort' => [1, 'none', null, 0],
        'uksort' => [1, 'none', null, 0],
        'preg_replace_callback' => [1, 'none', null, 0],
    ];

    /** @var list<Chain> */
    private array $chains = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, list<array{0:ClassInfo,1:MethodInfo}>> */
    private array $methodCache = [];

    private int $maxDepth = 8;
    private int $maxChains = 50;

    /** @param array<string, ClassInfo> $classes */
    public function __construct(
        private array $classes,
        private SinkScanner $scanner = new SinkScanner(),
    ) {
    }

    /**
     * @param list<string> $entryMethods
     * @return list<Chain>
     */
    public function find(
        array $entryMethods = ['__destruct', '__wakeup', '__unserialize'],
        int $maxDepth = 8,
        int $maxChains = 50,
    ): array {
        $this->chains = [];
        $this->seen = [];
        $this->maxDepth = $maxDepth;
        $this->maxChains = $maxChains;

        foreach ($this->classes as $class) {
            if (!$class->isInstantiable()) {
                continue;
            }
            foreach ($entryMethods as $entryMethod) {
                // 入口方法可以是继承来的
                $method = $this->effectiveMethod($class, $entryMethod);
                if ($method === null || !$this->isEntry($method, $entryMethods)) {
                    continue;
                }
                $visited = [$class->name . '::' . $method->name => true];
                $this->dfs($class, $method, $class, $method, [], $visited, 0);
                if (count($this->chains) >= $this->maxChains) {
                    break 2;
                }
            }
        }

        return $this->chains;
    }

    /** @param list<Edge> $edges @param array<string, true> $visited */
    private function dfs(
        ClassInfo $class,
        MethodInfo $method,
        ClassInfo $entryClass,
        MethodInfo $entryMethod,
        array $edges,
        array $visited,
        int $depth,
    ): void {
        if (count($this->chains) >= $this->maxChains) {
            return;
        }

        // 1) 当前方法里是否已经到达 sink
        foreach ($this->scanner->hitsIn($method) as $hit) {
            if ($hit->category === 'callable') {
                continue;
            }
            if (!$this->argControllable($hit->arg, $method, $edges)) {
                continue;
            }
            $chain = new Chain($entryClass, $entryMethod, $edges, $hit, $class, $method);
            $signature = $chain->signature();
            if (isset($this->seen[$signature])) {
                $this->preferBetterMapping($signature, $chain);
                continue;
            }
            $this->seen[$signature] = true;
            $this->chains[] = $chain;
            if (count($this->chains) >= $this->maxChains) {
                return;
            }
        }

        if ($depth >= $this->maxDepth) {
            return;
        }

        // 2) 展开调用点
        foreach ($method->calls as $call) {
            foreach ($this->edgesFor($class, $method, $call) as $edge) {
                $key = $edge->toClass->name . '::' . $edge->toMethod->name;
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $this->dfs(
                    $edge->toClass,
                    $edge->toMethod,
                    $entryClass,
                    $entryMethod,
                    [...$edges, $edge],
                    $visited,
                    $depth + 1,
                );
                unset($visited[$key]);
            }
        }

        // 3) 字符串上下文触发 __toString
        foreach ($method->stringUses as $use) {
            foreach ($this->classesWithMethod('__toString') as [$targetClass, $targetMethod]) {
                $key = $targetClass->name . '::__toString';
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $edge = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'toString',
                    $use->property,
                    [],
                    $use->line,
                );
                $this->dfs(
                    $targetClass,
                    $targetMethod,
                    $entryClass,
                    $entryMethod,
                    [...$edges, $edge],
                    $visited,
                    $depth + 1,
                );
                unset($visited[$key]);
            }
        }
    }

    /** @return list<Edge> */
    private function edgesFor(ClassInfo $class, MethodInfo $method, CallSite $call): array
    {
        $edges = [];

        // ($this->prop)(...)
        if ($call->kind === CallSite::CALL_PROPERTY && $call->receiverName !== null) {
            foreach ($this->classesWithMethod('__invoke') as [$targetClass, $targetMethod]) {
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'call_property',
                    $call->receiverName,
                    $this->mapArgs($call->args),
                    $call->line,
                );
            }
            return $edges;
        }

        // call_user_func / array_map / usort 等回调参数
        if ($call->kind === CallSite::FUNCTION_CALL) {
            $short = strtolower($this->shortName($call->target ?? ''));
            if (isset(self::CALLABLE_SINKS[$short])) {
                [$position, $mode, $arrayPosition, $elementParam] = self::CALLABLE_SINKS[$short];
                foreach ($this->callableEdges(
                    $class,
                    $method,
                    $call,
                    $position,
                    $mode,
                    $arrayPosition,
                    $elementParam,
                ) as $edge) {
                    $edges[] = $edge;
                }
            }
            return $edges;
        }

        // $this->prop->name 读取 → 目标类的 __get / __isset
        if (($call->kind === CallSite::PROPERTY_GET || $call->kind === CallSite::PROPERTY_ISSET)
            && $call->receiverName !== null) {
            $isGet = $call->kind === CallSite::PROPERTY_GET;
            foreach ($this->classesWithMethod($isGet ? '__get' : '__isset') as [$targetClass, $targetMethod]) {
                // 目标类（含父类）已经声明了这个属性时，读取它不会触发 __get / __isset
                if ($this->hasVisibleProperty($targetClass, (string) $call->target)) {
                    continue;
                }
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    $isGet ? 'get' : 'isset',
                    $call->receiverName,
                    [],
                    $call->line,
                );
            }
            return $edges;
        }

        // $this->prop->method(...)
        if ($call->kind === CallSite::METHOD_CALL
            && $call->receiverKind === ArgInfo::THIS_PROPERTY
            && $call->receiverName !== null) {
            if ($call->target !== null) {
                foreach ($this->classesWithMethod($call->target) as [$targetClass, $targetMethod]) {
                    $edges[] = new Edge(
                        $class,
                        $method,
                        $targetClass,
                        $targetMethod,
                        'method',
                        $call->receiverName,
                        $this->mapArgs($call->args),
                        $call->line,
                    );
                }
            }
            // __call 兜底
            foreach ($this->classesWithMethod('__call') as [$targetClass, $targetMethod]) {
                if ($call->target !== null && $this->effectiveMethod($targetClass, $call->target) !== null) {
                    continue;
                }
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'method',
                    $call->receiverName,
                    [],
                    $call->line,
                );
            }
            return $edges;
        }

        // $this->method(...)
        if ($call->kind === CallSite::METHOD_CALL
            && $call->receiverKind === 'this'
            && $call->target !== null) {
            $targetMethod = $this->effectiveMethod($class, $call->target);
            if ($targetMethod !== null) {
                $edges[] = new Edge(
                    $class,
                    $method,
                    $class,
                    $targetMethod,
                    'method',
                    '(this)',
                    $this->mapArgs($call->args),
                    $call->line,
                );
            }
            return $edges;
        }

        // Foo::method(...) / self::method(...)
        if ($call->kind === CallSite::STATIC_CALL
            && $call->target !== null
            && $call->receiverName !== null) {
            $targetClass = $this->resolveClassReference($class, $call->receiverName);
            if ($targetClass !== null) {
                $targetMethod = $this->effectiveMethod($targetClass, $call->target);
                if ($targetMethod !== null) {
                    $edges[] = new Edge(
                        $class,
                        $method,
                        $targetClass,
                        $targetMethod,
                        'static',
                        '(static)',
                        $this->mapArgs($call->args),
                        $call->line,
                    );
                }
            }
        }

        return $edges;
    }

    /** self / static / parent 这类相对类名解析。 */
    private function resolveClassReference(ClassInfo $context, string $reference): ?ClassInfo
    {
        $key = strtolower(ltrim($reference, '\\'));
        if ($key === 'self' || $key === 'static') {
            return $context;
        }
        if ($key === 'parent') {
            return $context->parent !== null ? ($this->classes[$context->parent] ?? null) : null;
        }
        return $this->classes[$reference] ?? null;
    }

    /** @return list<Edge> */
    /**
     * @return list<Edge>
     */
    private function callableEdges(
        ClassInfo $class,
        MethodInfo $method,
        CallSite $call,
        int $position,
        string $mode,
        ?int $arrayPosition,
        int $elementParam,
    ): array {
        $edges = [];
        $callable = $call->args[$position] ?? null;
        if ($callable === null) {
            return $edges;
        }

        $mapping = $this->callableArgMapping($call, $position, $mode, $arrayPosition, $elementParam);

        // ($this->fn)(...) / call_user_func($this->fn, ...)
        if ($callable->kind === ArgInfo::THIS_PROPERTY && $callable->name !== null) {
            foreach ($this->classesWithMethod('__invoke') as [$targetClass, $targetMethod]) {
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'callable',
                    $callable->name,
                    $mapping,
                    $call->line,
                );
            }
            return $edges;
        }

        // call_user_func([$this->obj, 'method'], ...)
        if ($callable->kind === ArgInfo::CALLABLE_ARRAY
            && $callable->name !== null
            && is_string($callable->literal)) {
            $targetName = $callable->literal;
            foreach ($this->classesWithMethod($targetName) as [$targetClass, $targetMethod]) {
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'callable_array',
                    $callable->name,
                    $mapping,
                    $call->line,
                );
            }
            foreach ($this->classesWithMethod('__call') as [$targetClass, $targetMethod]) {
                if ($this->effectiveMethod($targetClass, $targetName) !== null) {
                    continue;
                }
                $edges[] = new Edge(
                    $class,
                    $method,
                    $targetClass,
                    $targetMethod,
                    'callable_array',
                    $callable->name,
                    [],
                    $call->line,
                );
            }
        }

        return $edges;
    }

    /**
     * 回调实参映射：把被调方法的形参位置映射到调用点上的实参来源。
     *
     * @return array<int, ArgInfo>
     */
    private function callableArgMapping(
        CallSite $call,
        int $position,
        string $mode,
        ?int $arrayPosition,
        int $elementParam,
    ): array {
        if ($mode === 'forward') {
            return $this->mapArgs(array_slice($call->args, $position + 1));
        }

        if ($mode === 'none' || $arrayPosition === null) {
            return [];
        }

        $source = $call->args[$arrayPosition] ?? null;
        if ($source === null) {
            return [];
        }
        $property = $this->arraySourceProperty($source);
        if ($property === null) {
            return [];
        }

        if ($mode === 'args-array') {
            // 参数数组的第 k 个元素 → 第 k 个形参
            $mapping = [];
            for ($index = 0; $index < 10; $index++) {
                $mapping[$index] = new ArgInfo(ArgInfo::ARRAY_ELEMENT, $property, null, $index);
            }
            return $mapping;
        }

        // element：数组元素 → 指定的那个形参
        return [$elementParam => new ArgInfo(ArgInfo::ARRAY_ELEMENT, $property, null, 0)];
    }

    /** 回调实参数组来自哪个属性。 */
    private function arraySourceProperty(ArgInfo $arg): ?string
    {
        if ($arg->kind === ArgInfo::THIS_PROPERTY && $arg->name !== null) {
            return $arg->name;
        }
        if ($arg->kind === ArgInfo::CONCAT) {
            return $arg->targetProperty();
        }
        return null;
    }

    private function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /** @param list<ArgInfo> $args @return array<int, ArgInfo> */
    private function mapArgs(array $args): array
    {
        $mapping = [];
        foreach ($args as $position => $arg) {
            $mapping[$position] = $arg;
        }
        return $mapping;
    }

    /** @return list<array{0:ClassInfo,1:MethodInfo}> */
    private function classesWithMethod(string $name): array
    {
        $cacheKey = strtolower($name);
        if (isset($this->methodCache[$cacheKey])) {
            return $this->methodCache[$cacheKey];
        }

        $result = [];
        foreach ($this->classes as $class) {
            if (!$class->isInstantiable()) {
                continue;
            }
            $method = $this->effectiveMethod($class, $name);
            if ($method !== null && $method->isPublic()) {
                $result[] = [$class, $method];
            }
        }
        return $this->methodCache[$cacheKey] = $result;
    }

    private function effectiveMethod(ClassInfo $class, string $name): ?MethodInfo
    {
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[$current->name])) {
            $seen[$current->name] = true;
            $method = $current->method($name);
            if ($method !== null) {
                return $method;
            }
            $current = $current->parent !== null
                ? ($this->classes[$current->parent] ?? null)
                : null;
        }
        return null;
    }

    /**
     * sink 实参是否可控。
     *
     * 属性/拼接直接可控；方法形参要看上游调用点传进来的实参：
     *   - 入口方法的形参来自反序列化数据，视为可控；
     *   - 其余方法的形参沿最后一条边的 argMapping 回溯，传的是常量就判定不可控。
     *
     * @param list<Edge> $edges
     */
    private function argControllable(ArgInfo $arg, MethodInfo $method, array $edges, int $depth = 0): bool
    {
        if ($arg->isControllable()) {
            return true;
        }
        if ($arg->kind === ArgInfo::PARAM_ELEMENT) {
            // 入口魔术方法的参数数组本身就是反序列化数据
            return $edges === [] && $arg->name !== null && $method->paramNamed($arg->name) !== null;
        }
        if ($arg->kind !== ArgInfo::VARIABLE || $arg->name === null) {
            return false;
        }

        $param = $method->paramNamed($arg->name);
        if ($param === null) {
            return false;
        }

        // 入口魔术方法的实参由 unserialize 提供
        if ($edges === []) {
            return true;
        }
        if ($depth > 12) {
            return false;
        }

        $edge = $edges[count($edges) - 1];
        $mapped = $edge->argMapping[$param->position] ?? null;
        if ($mapped === null) {
            // 回调类边（数组元素、__call 的实参数组）无法静态确定
            return false;
        }

        return $this->argControllable($mapped, $edge->fromMethod, array_slice($edges, 0, -1), $depth + 1);
    }

    /** 目标类（含父类）是否有可见（public）属性，有则不会触发 __get / __isset。 */
    private function hasVisibleProperty(ClassInfo $class, string $name): bool
    {
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[$current->name])) {
            $seen[$current->name] = true;
            $property = $current->property($name);
            if ($property !== null) {
                return $property->isPublic();
            }
            $current = $current->parent !== null ? ($this->classes[$current->parent] ?? null) : null;
        }
        return false;
    }

    /**
     * 同一个 signature 已存在时，保留能把 sink 实参映射出来的那条链
     * （典型场景：同方法里既有 first-class callable 又有真实调用）。
     */
    private function preferBetterMapping(string $signature, Chain $candidate): void
    {
        foreach ($this->chains as $index => $existing) {
            if ($existing->signature() !== $signature) {
                continue;
            }
            if ($candidate->mappedArgCount() > $existing->mappedArgCount()) {
                $this->chains[$index] = $candidate;
            }
            return;
        }
    }

    /** @param list<string> $entries */
    private function isEntry(MethodInfo $method, array $entries): bool
    {
        if (!$method->isPublic()) {
            return false;
        }
        foreach ($entries as $entry) {
            if (strcasecmp($method->name, $entry) === 0) {
                return true;
            }
        }
        return false;
    }
}
