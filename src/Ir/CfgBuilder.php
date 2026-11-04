<?php
declare(strict_types=1);

namespace PopChain\Ir;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/**
 * 把方法体编译成控制流图。
 *
 * 目标不是精确的可执行语义，而是给分析后端（符号执行、数据流）一个
 * 带分支/循环/异常出口的语句级骨架：节点上是源码片段与行号，边上有 true/false
 * 之类的标签，足够外部引擎自己再做表达式级建模。
 */
final class CfgBuilder
{
    /** @var list<CfgNode> */
    private array $nodes = [];

    private int $nextId = 0;

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /** @param array<Node>|null $stmts */
    public function build(string $class, string $method, ?array $stmts, int $startLine = 0): MethodCfg
    {
        $this->nodes = [];
        $this->nextId = 0;

        $entry = $this->newNode('entry', $startLine, '');
        $pairs = [['from' => $entry, 'label' => null]];

        $pairs = $this->compile($stmts ?? [], $pairs);

        $exit = $this->newNode('exit', $startLine, '');
        foreach ($pairs as $pair) {
            $this->link($pair['from'], $exit, $pair['label']);
        }

        return new MethodCfg($class, $method, $this->nodes, $entry, [$exit]);
    }

    /**
     * @param array<Node> $stmts
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compile(array $stmts, array $incoming): array
    {
        $exits = $incoming;

        foreach ($stmts as $stmt) {
            if ($exits === []) {
                // 之前的语句已经终止（return/throw），后面的代码不可达
                break;
            }
            $exits = $this->compileStmt($stmt, $exits);
        }

        return $exits;
    }

    /**
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compileStmt(Node $stmt, array $incoming): array
    {
        if ($stmt instanceof Stmt\If_) {
            return $this->compileIf($stmt, $incoming);
        }
        if ($stmt instanceof Stmt\While_ || $stmt instanceof Stmt\Do_
            || $stmt instanceof Stmt\For_ || $stmt instanceof Stmt\Foreach_) {
            return $this->compileLoop($stmt, $incoming);
        }
        if ($stmt instanceof Stmt\Switch_) {
            return $this->compileSwitch($stmt, $incoming);
        }
        if ($stmt instanceof Stmt\TryCatch_) {
            return $this->compileTry($stmt, $incoming);
        }
        if ($stmt instanceof Stmt\Return_) {
            $node = $this->newNode('return', $stmt->getStartLine(), $this->printStatement($stmt));
            $this->attach($node, $incoming);
            return [];
        }
        if ($stmt instanceof Stmt\Throw_) {
            $node = $this->newNode('throw', $stmt->getStartLine(), $this->printStatement($stmt));
            $this->attach($node, $incoming);
            return [];
        }
        if ($stmt instanceof Stmt\Break_ || $stmt instanceof Stmt\Continue_) {
            $kind = $stmt instanceof Stmt\Break_ ? 'break' : 'continue';
            $node = $this->newNode('jump', $stmt->getStartLine(), $this->printStatement($stmt), ['jump' => $kind]);
            $this->attach($node, $incoming);
            return [];
        }

        $node = $this->newNode('statement', $stmt->getStartLine(), $this->printStatement($stmt));
        $this->attach($node, $incoming);
        return [['from' => $node, 'label' => null]];
    }

    /**
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compileIf(Stmt\If_ $stmt, array $incoming): array
    {
        $cond = $this->newNode(
            'condition',
            $stmt->getStartLine(),
            $this->printer->prettyPrintExpr($stmt->cond),
            ['condition' => 'if'],
        );
        $this->attach($cond, $incoming);

        $exits = $this->compile($stmt->stmts, [['from' => $cond, 'label' => 'true']]);

        if ($stmt->else !== null) {
            $exits = array_merge($exits, $this->compile($stmt->else->stmts, [['from' => $cond, 'label' => 'false']]));
        } else {
            $exits[] = ['from' => $cond, 'label' => 'false'];
        }

        // elseif 分支：从同一个条件节点分出去，近似为并列分支
        foreach ($stmt->elseifs as $index => $elseif) {
            $branchCond = $this->newNode(
                'condition',
                $elseif->getStartLine(),
                $this->printer->prettyPrintExpr($elseif->cond),
                ['condition' => 'elseif', 'index' => $index],
            );
            $this->link($cond, $branchCond, 'false');
            $branchExits = $this->compile($elseif->stmts, [['from' => $branchCond, 'label' => 'true']]);
            $branchExits[] = ['from' => $branchCond, 'label' => 'false'];
            // 去掉之前从 cond 出来的 false 边，用 elseif 链代替
            $exits = array_values(array_filter(
                array_merge($exits, $branchExits),
                static fn (array $pair): bool => !($pair['from'] === $cond && $pair['label'] === 'false'),
            ));
        }

        return $exits;
    }

    /**
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compileLoop(Node $stmt, array $incoming): array
    {
        $header = $this->newNode(
            'loop',
            $stmt->getStartLine(),
            $this->printer->prettyPrint([$stmt]),
            ['loop' => $stmt::class],
        );
        $this->attach($header, $incoming);

        $bodyEntries = [['from' => $header, 'label' => 'true']];
        $bodyExits = $this->compile($stmt->stmts ?? [], $bodyEntries);

        foreach ($bodyExits as $pair) {
            $this->link($pair['from'], $header, $pair['label']);
        }

        return [['from' => $header, 'label' => 'false']];
    }

    /**
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compileSwitch(Stmt\Switch_ $stmt, array $incoming): array
    {
        $cond = $this->newNode(
            'switch',
            $stmt->getStartLine(),
            $this->printer->prettyPrintExpr($stmt->cond),
        );
        $this->attach($cond, $incoming);

        $exits = [];
        foreach ($stmt->cases as $case) {
            $exits = array_merge($exits, $this->compile($case->stmts, [['from' => $cond, 'label' => 'case']]));
        }
        if ($exits === []) {
            $exits[] = ['from' => $cond, 'label' => null];
        }

        return $exits;
    }

    /**
     * @param list<array{from:int,label:?string}> $incoming
     * @return list<array{from:int,label:?string}>
     */
    private function compileTry(Stmt\TryCatch_ $stmt, array $incoming): array
    {
        $guard = $this->newNode('try', $stmt->getStartLine(), 'try');
        $this->attach($guard, $incoming);

        $exits = $this->compile($stmt->stmts, [['from' => $guard, 'label' => 'true']]);

        foreach ($stmt->catches as $catch) {
            $exits = array_merge($exits, $this->compile($catch->stmts, [['from' => $guard, 'label' => 'exception']]));
        }

        if ($stmt->finally !== null) {
            $exits = $this->compile($stmt->finally->stmts, $exits);
        }

        return $exits;
    }

    private function newNode(string $kind, int $line, string $code, array $meta = []): int
    {
        $id = $this->nextId++;
        $this->nodes[] = new CfgNode($id, $kind, $line, $code, [], $meta);
        return $id;
    }

    /** @param list<array{from:int,label:?string}> $incoming */
    private function attach(int $to, array $incoming): void
    {
        foreach ($incoming as $pair) {
            $this->link($pair['from'], $to, $pair['label']);
        }
    }

    private function link(int $from, int $to, ?string $label): void
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $from) {
                $node->successors[] = ['to' => $to, 'label' => $label];
                return;
            }
        }
    }

    private function printStatement(Stmt $stmt): string
    {
        $code = $this->printer->prettyPrint([$stmt]);
        return strlen($code) > 500 ? substr($code, 0, 500) . '...' : $code;
    }
}
