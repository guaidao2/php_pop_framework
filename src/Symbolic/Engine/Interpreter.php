<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PhpParser\Node;
use PhpParser\Node\Expr as PExpr;
use PhpParser\Node\Stmt as PStmt;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\Simplifier;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Security\Finding;
use PopChain\Symbolic\Security\FindingCollector;
use PopChain\Symbolic\Security\Sink;
use PopChain\Symbolic\Security\SinkCatalog;
use PopChain\Symbolic\Solver\Solver;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;
use PopChain\Symbolic\Symbolic\Unknown;
use PopChain\Symbolic\Value\ArrayValue;
use PopChain\Symbolic\Value\ClosureValue;
use PopChain\Symbolic\Value\ObjectValue;
use PopChain\Symbolic\Value\ScalarValue;
use PopChain\Symbolic\Value\UnknownValue;
use PopChain\Symbolic\Value\Value;
use PopChain\Symbolic\Value\ValueFactory;

/**
 * AST 符号执行解释器。
 *
 * 执行模型：
 *   - 语句序列返回一组可能的状态（分支处 fork）
 *   - 分支条件先化简，能定值则只走一条，否则 fork 并做约束求解剪枝
 *   - 函数调用内联执行，多返回值合并；超过深度限制后退化为未知值
 *   - 循环展开固定次数，超出则标记 truncated
 *
 * 这是"面向安全审计"的符号执行：不追求完整的 PHP 语义，
 * 但保证不因近似而漏掉可达的污点汇聚点。
 */
final class Interpreter
{
    private int $pathCount = 0;

    private bool $truncated = false;

    private float $deadline;

    private int $statementCount = 0;

    /** @var array<string, list<string>> */
    private array $lineCache = [];

    private ?ObjectValue $lastThisValue = null;

    /** @var array<string, Value> define()/const 定义的常量 */
    private array $constants = [];

    /** @var array<string, Value> 静态属性 */
    private array $staticProperties = [];

    /** @var array<string, ClassDef> 匿名类 */
    private array $anonymousClasses = [];

    public function __construct(
        private readonly Program $program,
        private readonly AnalyzerOptions $options,
        private readonly Builtins $builtins,
        private readonly FindingCollector $findings,
        private readonly Solver $solver,
    ) {
        $this->deadline = microtime(true) + $options->timeout;
    }

    public function pathCount(): int
    {
        return $this->pathCount;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function statementCount(): int
    {
        return $this->statementCount;
    }

    /**
     * @return list<State>
     */
    public function executeFile(string $file, State $state): array
    {
        $stmts = $this->program->statements($file);
        if ($stmts === null) {
            return [$state];
        }

        $state->file = $file;
        $state->namespace = $this->program->fileNamespace($file);
        $state->uses = $this->program->fileUses($file);

        return $this->executeStmts($stmts, $state);
    }

    // ==================================================================
    // 语句
    // ==================================================================

    /**
     * @param list<Node> $stmts
     * @return list<State>
     */
    private function executeStmts(array $stmts, State $state): array
    {
        $states = [$state];

        foreach ($stmts as $stmt) {
            if ($this->timedOut()) {
                $this->truncated = true;
                break;
            }

            $next = [];
            foreach ($states as $current) {
                if ($current->isTerminated()) {
                    $next[] = $current;
                    continue;
                }
                foreach ($this->executeStmt($stmt, $current) as $result) {
                    $next[] = $result;
                }
            }

            $states = $this->limitStates($next);
            if ($states === []) {
                break;
            }
        }

        return $states;
    }

    /**
     * @return list<State>
     */
    private function executeStmt(Node $stmt, State $state): array
    {
        $this->statementCount++;

        if ($stmt instanceof PStmt\Expression) {
            $this->evalExpr($stmt->expr, $state);

            return [$state];
        }

        if ($stmt instanceof PStmt\Echo_) {
            $values = [];
            foreach ($stmt->exprs as $expr) {
                $values[] = $this->evalExpr($expr, $state);
            }
            $this->checkSink('echo', $values, $state, $stmt);

            return [$state];
        }

        if ($stmt instanceof PStmt\If_) {
            return $this->executeIf($stmt, $state);
        }

        if ($stmt instanceof PStmt\Return_) {
            $value = $stmt->expr !== null
                ? $this->evalExpr($stmt->expr, $state)
                : ValueFactory::fromConstant(null);
            $state->terminate(new ReturnSignal($value));

            return [$state];
        }

        if ($stmt instanceof PStmt\While_) {
            return $this->executeWhile($stmt, $state);
        }

        if ($stmt instanceof PStmt\Do_) {
            return $this->executeDo($stmt, $state);
        }

        if ($stmt instanceof PStmt\For_) {
            return $this->executeFor($stmt, $state);
        }

        if ($stmt instanceof PStmt\Foreach_) {
            return $this->executeForeach($stmt, $state);
        }

        if ($stmt instanceof PStmt\Switch_) {
            return $this->executeSwitch($stmt, $state);
        }

        if ($stmt instanceof PStmt\Break_) {
            $state->terminate(new BreakSignal($stmt->num !== null ? (int) $this->constantValue($stmt->num) : 1));

            return [$state];
        }

        if ($stmt instanceof PStmt\Continue_) {
            $state->terminate(new ContinueSignal($stmt->num !== null ? (int) $this->constantValue($stmt->num) : 1));

            return [$state];
        }

        if ($stmt instanceof PStmt\Throw_) {
            $state->terminate(new ThrowSignal($this->evalExpr($stmt->expr, $state)));

            return [$state];
        }

        if ($stmt instanceof PStmt\TryCatch) {
            return $this->executeTryCatch($stmt, $state);
        }

        if ($stmt instanceof PStmt\Global_) {
            foreach ($stmt->vars as $var) {
                if ($var instanceof PExpr\Variable && is_string($var->name)) {
                    $name = '$' . $var->name;
                    $state->globalAliases[$name] = true;
                    $state->env[$name] = $state->globals->vars[$name]
                        ?? new UnknownValue(ValueFactory::freshName($name));
                }
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\Static_) {
            foreach ($stmt->vars as $staticVar) {
                $name = $staticVar->var instanceof PExpr\Variable && is_string($staticVar->var->name)
                    ? $staticVar->var->name
                    : null;
                if ($name === null || $state->has($name)) {
                    continue;
                }
                $state->set($name, $staticVar->default !== null
                    ? $this->evalExpr($staticVar->default, $state)
                    : ValueFactory::fromConstant(null));
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\Unset_) {
            foreach ($stmt->vars as $var) {
                if ($var instanceof PExpr\Variable && is_string($var->name)) {
                    $state->remove($var->name);
                } elseif ($var instanceof PExpr\ArrayDimFetch) {
                    $this->unsetArrayPath($var, $state);
                }
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\Namespace_) {
            $previous = $state->namespace;
            $state->namespace = $stmt->name?->toString();
            $results = $this->executeStmts($stmt->stmts, $state);
            foreach ($results as $result) {
                $result->namespace = $previous;
            }

            return $results;
        }

        if ($stmt instanceof PStmt\Use_) {
            foreach ($stmt->uses as $use) {
                $alias = $use->getAlias()->toString();
                $state->uses[strtolower($alias)] = $use->name->toString();
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                $alias = $use->getAlias()->toString();
                $state->uses[strtolower($alias)] = $prefix . '\\' . $use->name->toString();
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\Declare_) {
            return $stmt->stmts !== null ? $this->executeStmts($stmt->stmts, $state) : [$state];
        }

        if ($stmt instanceof PStmt\Const_) {
            foreach ($stmt->consts as $const) {
                $this->constants[$const->name->toString()] = $this->evalExpr($const->value, $state);
            }

            return [$state];
        }

        if ($stmt instanceof PStmt\Block) {
            return $this->executeStmts($stmt->stmts, $state);
        }

        // 声明、Nop、InlineHTML、Goto/Label 等不需要执行
        return [$state];
    }

    /**
     * @return list<State>
     */
    private function executeIf(PStmt\If_ $node, State $state): array
    {
        return $this->branch(
            $node->cond,
            $state,
            fn (State $s): array => $this->executeStmts($node->stmts, $s),
            fn (State $s): array => $this->executeElse($node->elseifs, $node->else, $s),
        );
    }

    /**
     * @param list<PStmt\ElseIf_> $elseifs
     * @return list<State>
     */
    private function executeElse(array $elseifs, ?PStmt\Else_ $else, State $state): array
    {
        if ($elseifs === []) {
            return $else !== null ? $this->executeStmts($else->stmts, $state) : [$state];
        }

        $first = $elseifs[0];
        $rest = array_slice($elseifs, 1);

        return $this->branch(
            $first->cond,
            $state,
            fn (State $s): array => $this->executeStmts($first->stmts, $s),
            fn (State $s): array => $this->executeElse($rest, $else, $s),
        );
    }

    /**
     * 通用条件分支。
     *
     * @param callable(State): list<State> $then
     * @param callable(State): list<State> $else
     * @return list<State>
     */
    private function branch(Node $condNode, State $state, callable $then, callable $else): array
    {
        $condValue = $this->evalExpr($condNode, $state);
        $condition = Simplifier::simplify($condValue->toExpr());

        if ($condition->isConstant()) {
            return $condition->constantValue() ? $then($state) : $else($state);
        }

        if ($this->pathCount >= $this->options->maxPaths) {
            $this->truncated = true;

            return $this->limitStates($then($state->copy()));
        }

        $results = [];

        $trueState = $state->copy();
        $trueState->addConstraint($condition);
        if ($trueState->isFeasible($this->solver)) {
            $this->pathCount++;
            foreach ($then($trueState) as $result) {
                $results[] = $result;
            }
        }

        $falseState = $state->copy();
        $falseState->addConstraint(Simplifier::unary('!', $condition));
        if ($falseState->isFeasible($this->solver)) {
            $this->pathCount++;
            foreach ($else($falseState) as $result) {
                $results[] = $result;
            }
        }

        return $this->limitStates($results);
    }

    /**
     * @return list<State>
     */
    private function executeWhile(PStmt\While_ $node, State $state): array
    {
        return $this->executeLoop(
            $node->cond,
            $node->stmts,
            null,
            $state,
        );
    }

    /**
     * @return list<State>
     */
    private function executeDo(PStmt\Do_ $node, State $state): array
    {
        $results = [];
        $frontier = [$state];

        for ($iteration = 0; $iteration < $this->options->maxLoopIterations && $frontier !== []; $iteration++) {
            $next = [];
            foreach ($frontier as $current) {
                $bodyStates = $this->executeStmts($node->stmts, $current);
                foreach ($bodyStates as $bodyState) {
                    if ($bodyState->signal instanceof BreakSignal) {
                        if ($bodyState->signal->levels <= 1) {
                            $bodyState->signal = null;
                            $results[] = $bodyState;
                        } else {
                            $bodyState->signal = new BreakSignal($bodyState->signal->levels - 1);
                            $results[] = $bodyState;
                        }
                        continue;
                    }
                    if ($bodyState->signal instanceof ContinueSignal) {
                        $bodyState->signal = null;
                    }
                    if ($bodyState->signal !== null) {
                        $results[] = $bodyState;
                        continue;
                    }

                    $condValue = $this->evalExpr($node->cond, $bodyState);
                    $condition = Simplifier::simplify($condValue->toExpr());
                    if ($condition->isConstant()) {
                        if ($condition->constantValue()) {
                            $next[] = $bodyState;
                        } else {
                            $results[] = $bodyState;
                        }
                        continue;
                    }

                    $continueState = $bodyState->copy();
                    $continueState->addConstraint($condition);
                    if ($continueState->isFeasible($this->solver)) {
                        $next[] = $continueState;
                    }
                    $exitState = $bodyState->copy();
                    $exitState->addConstraint(Simplifier::unary('!', $condition));
                    if ($exitState->isFeasible($this->solver)) {
                        $results[] = $exitState;
                    }
                }
            }
            $frontier = $next;
        }

        if ($frontier !== []) {
            $this->truncated = true;
            foreach ($frontier as $remaining) {
                $results[] = $remaining;
            }
        }

        return $results;
    }

    /**
     * @return list<State>
     */
    private function executeFor(PStmt\For_ $node, State $state): array
    {
        foreach ($node->init as $init) {
            $this->evalExpr($init, $state);
        }

        return $this->executeLoop(
            $node->cond[0] ?? null,
            $node->stmts,
            $node->loop,
            $state,
        );
    }

    /**
     * while / for 的通用展开实现。
     *
     * @param list<Node> $body
     * @param list<Node>|null $afterIteration
     * @return list<State>
     */
    private function executeLoop(?Node $condNode, array $body, ?array $afterIteration, State $state): array
    {
        $exitStates = [];
        $frontier = [$state];

        for ($iteration = 0; $iteration < $this->options->maxLoopIterations && $frontier !== []; $iteration++) {
            $next = [];

            foreach ($frontier as $current) {
                // 循环条件
                if ($condNode !== null) {
                    $condValue = $this->evalExpr($condNode, $current);
                    $condition = Simplifier::simplify($condValue->toExpr());

                    if ($condition->isConstant() && !$condition->constantValue()) {
                        $exitStates[] = $current;
                        continue;
                    }
                    $enterState = $current->copy();
                    $enterState->addConstraint($condition);
                    if (!$enterState->isFeasible($this->solver)) {
                        $exit = $current->copy();
                        $exit->addConstraint(Simplifier::unary('!', $condition));
                        if ($exit->isFeasible($this->solver)) {
                            $exitStates[] = $exit;
                        }
                        continue;
                    }
                    if (!$condition->isConstant()) {
                        $exit = $current->copy();
                        $exit->addConstraint(Simplifier::unary('!', $condition));
                        if ($exit->isFeasible($this->solver)) {
                            $exitStates[] = $exit;
                        }
                    }
                    $this->pathCount++;
                    $iterationState = $enterState;
                } else {
                    $iterationState = $current->copy();
                }

                $bodyStates = $this->executeStmts($body, $iterationState);

                foreach ($bodyStates as $bodyState) {
                    if ($bodyState->signal instanceof BreakSignal) {
                        if ($bodyState->signal->levels <= 1) {
                            $bodyState->signal = null;
                            $exitStates[] = $bodyState;
                        } else {
                            $bodyState->signal = new BreakSignal($bodyState->signal->levels - 1);
                            $exitStates[] = $bodyState;
                        }
                        continue;
                    }
                    if ($bodyState->signal instanceof ContinueSignal) {
                        if ($bodyState->signal->levels <= 1) {
                            $bodyState->signal = null;
                        } else {
                            $bodyState->signal = new ContinueSignal($bodyState->signal->levels - 1);
                        }
                    }
                    if ($bodyState->signal !== null) {
                        $exitStates[] = $bodyState;
                        continue;
                    }

                    if ($afterIteration !== null) {
                        foreach ($afterIteration as $loopExpr) {
                            $this->evalExpr($loopExpr, $bodyState);
                        }
                    }
                    $next[] = $bodyState;
                }
            }

            $frontier = $this->limitStates($next);
        }

        if ($frontier !== []) {
            $this->truncated = true;
            foreach ($frontier as $remaining) {
                $exitStates[] = $remaining;
            }
        }

        return $exitStates;
    }

    /**
     * @return list<State>
     */
    private function executeForeach(PStmt\Foreach_ $node, State $state): array
    {
        $collection = $this->evalExpr($node->expr, $state);
        $exitStates = [];

        if ($collection instanceof ArrayValue) {
            $items = $collection->items();
            $count = 0;
            foreach ($items as $key => $value) {
                if ($count++ >= $this->options->maxLoopIterations) {
                    $this->truncated = true;
                    break;
                }
                $iterationState = $state->copy();
                $this->assignForeachVars($node, $iterationState, ValueFactory::fromConstant($key), $value);
                $writeBack = $this->foreachReferenceWriter($node, $key);
                foreach ($this->runLoopBody($node->stmts, $iterationState, $exitStates, $writeBack) as $result) {
                    $exitStates[] = $result;
                }
                if ($this->lastForeachBroke) {
                    break;
                }
            }

            if ($items === [] || $collection->isOpen()) {
                $iterationState = $state->copy();
                $this->assignForeachVars(
                    $node,
                    $iterationState,
                    ValueFactory::unknown(),
                    $collection->elementValue(),
                );
                foreach ($this->runLoopBody($node->stmts, $iterationState, $exitStates) as $result) {
                    $exitStates[] = $result;
                }
            }

            if ($items === [] && !$collection->isOpen()) {
                $exitStates[] = $state;
            }

            return $exitStates;
        }

        // 未知集合：执行一次循环体，变量带集合的污点
        $element = new UnknownValue(ValueFactory::freshName('foreach-element'), $collection->taint);
        $iterationState = $state->copy();
        $this->assignForeachVars($node, $iterationState, ValueFactory::unknown($collection->taint), $element);
        foreach ($this->runLoopBody($node->stmts, $iterationState, $exitStates) as $result) {
            $exitStates[] = $result;
        }
        $exitStates[] = $state->copy();

        return $exitStates;
    }

    /** @var bool */
    private bool $lastForeachBroke = false;

    /**
     * 生成 foreach 引用循环的写回回调：foreach ($arr as &$v) { $v = ...; }
     */
    private function foreachReferenceWriter(PStmt\Foreach_ $node, int|string $key): ?callable
    {
        if (!$node->byRef) {
            return null;
        }
        if (!$node->expr instanceof PExpr\Variable || !is_string($node->expr->name)) {
            return null;
        }
        if (!$node->valueVar instanceof PExpr\Variable || !is_string($node->valueVar->name)) {
            return null;
        }
        $arrayName = $node->expr->name;
        $valueName = $node->valueVar->name;

        return static function (State $state) use ($key, $arrayName, $valueName): void {
            $array = $state->get($arrayName);
            if ($array instanceof ArrayValue) {
                $state->set($arrayName, $array->withKey($key, $state->get($valueName)));
            }
        };
    }

    private function assignForeachVars(PStmt\Foreach_ $node, State $state, Value $key, Value $value): void
    {
        if ($node->keyVar !== null) {
            $this->assignTo($node->keyVar, $key, $state);
        }
        $this->assignTo($node->valueVar, $value, $state);
    }

    /**
     * 执行循环体，处理 continue；break 保留在状态信号中。
     *
     * @param list<Node> $body
     * @param list<State> $sink 已退出的状态
     * @return list<State>
     */
    private function runLoopBody(array $body, State $state, array &$sink, ?callable $afterBody = null): array
    {
        $this->lastForeachBroke = false;
        $results = [];
        $states = $this->executeStmts($body, $state);

        foreach ($states as $result) {
            if ($afterBody !== null) {
                $afterBody($result);
            }

            if ($result->signal instanceof ContinueSignal) {
                if ($result->signal->levels <= 1) {
                    $result->signal = null;
                } else {
                    $result->signal = new ContinueSignal($result->signal->levels - 1);
                }
                $results[] = $result;

                continue;
            }
            if ($result->signal instanceof BreakSignal) {
                if ($result->signal->levels <= 1) {
                    $result->signal = null;
                    $sink[] = $result;
                    $this->lastForeachBroke = true;
                } else {
                    $result->signal = new BreakSignal($result->signal->levels - 1);
                    $sink[] = $result;
                    $this->lastForeachBroke = true;
                }

                continue;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @return list<State>
     */
    private function executeSwitch(PStmt\Switch_ $node, State $state): array
    {
        $condValue = $this->evalExpr($node->cond, $state);
        $condExpr = $condValue->toExpr();
        $cases = $node->cases;
        $results = [];
        $anyPossible = false;
        $allCertain = true;

        foreach ($cases as $index => $case) {
            if ($case->cond === null) {
                continue;
            }
            $caseValue = $this->evalExpr($case->cond, $state);
            $comparison = Expr::bin('==', $condExpr, $caseValue->toExpr());
            $truth = $comparison->isConstant() ? (bool) $comparison->constantValue() : null;
            if ($truth === false) {
                continue;
            }
            if ($truth === null) {
                $allCertain = false;
            }
            $caseState = $state->copy();
            $caseState->addConstraint($comparison);
            if (!$caseState->isFeasible($this->solver)) {
                continue;
            }
            $anyPossible = true;

            // fallthrough：从匹配的 case 一直执行到 switch 末尾
            $body = [];
            for ($j = $index, $count = count($cases); $j < $count; $j++) {
                $body = array_merge($body, $cases[$j]->stmts);
            }
            foreach ($this->clearLoopSignals($this->executeStmts($body, $caseState)) as $result) {
                $results[] = $result;
            }
        }

        // default：无 case 匹配，或存在无法确定是否匹配的 case 时保守执行
        if (!$anyPossible || !$allCertain) {
            foreach ($cases as $case) {
                if ($case->cond === null) {
                    foreach ($this->clearLoopSignals($this->executeStmts($case->stmts, $state->copy())) as $result) {
                        $results[] = $result;
                    }
                    break;
                }
            }
        }

        return $results === [] ? [$state] : $results;
    }

    /**
     * @param list<State> $states
     * @return list<State>
     */
    private function clearLoopSignals(array $states): array
    {
        $results = [];
        foreach ($states as $state) {
            if ($state->signal instanceof BreakSignal || $state->signal instanceof ContinueSignal) {
                $state->signal = null;
            }
            $results[] = $state;
        }

        return $results;
    }

    /**
     * @return list<State>
     */
    private function executeTryCatch(PStmt\TryCatch $node, State $state): array
    {
        $states = $this->executeStmts($node->stmts, $state);
        $results = [];

        foreach ($states as $result) {
            if ($result->signal instanceof ThrowSignal && $node->catches !== []) {
                $catch = $node->catches[0];
                $catchState = $result->copy();
                $catchState->signal = null;
                if ($catch->var !== null) {
                    $this->assignTo($catch->var, $result->signal->value, $catchState);
                }
                foreach ($this->executeStmts($catch->stmts, $catchState) as $catchResult) {
                    $results[] = $catchResult;
                }
                continue;
            }
            $results[] = $result;
        }

        if ($node->finally !== null) {
            $finalResults = [];
            foreach ($results as $result) {
                foreach ($this->executeStmts($node->finally->stmts, $result) as $finalResult) {
                    $finalResults[] = $finalResult;
                }
            }
            $results = $finalResults;
        }

        return $results;
    }

    // ==================================================================
    // 表达式
    // ==================================================================

    private function evalExpr(Node $node, State $state): Value
    {
        if ($node instanceof Node\Scalar\String_) {
            return ValueFactory::fromConstant($node->value);
        }
        if ($node instanceof Node\Scalar\Int_) {
            return ValueFactory::fromConstant($node->value);
        }
        if ($node instanceof Node\Scalar\Float_) {
            return ValueFactory::fromConstant($node->value);
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            return $this->evalInterpolated($node, $state);
        }
        if ($node instanceof Node\Scalar\MagicConst) {
            return $this->evalMagicConst($node, $state);
        }

        if ($node instanceof PExpr\Variable) {
            if (is_string($node->name)) {
                return $state->get($node->name);
            }

            // 动态变量名 $$name / ${expr}
            $nameValue = $this->evalExpr($node->name, $state);
            $nameExpr = Simplifier::simplify($nameValue->toExpr());
            if ($nameExpr->isConstant() && is_string($nameExpr->constantValue())) {
                return $state->get($nameExpr->constantValue());
            }

            return new ScalarValue(
                new VarExpr('${' . $nameExpr->toString() . '}', Type::Mixed),
                $nameValue->taint,
            );
        }

        if ($node instanceof PExpr\Assign || $node instanceof PExpr\AssignRef) {
            $value = $this->evalExpr($node->expr, $state);
            $this->assignTo($node->var, $value, $state);

            return $value;
        }

        if ($node instanceof PExpr\AssignOp) {
            return $this->evalAssignOp($node, $state);
        }

        if ($node instanceof PExpr\BinaryOp) {
            return $this->evalBinary($node, $state);
        }

        if ($node instanceof PExpr\BooleanNot) {
            $value = $this->evalExpr($node->expr, $state);

            return new ScalarValue(
                Expr::unary('!', $value->toExpr()),
                Taint::none(),
            );
        }

        if ($node instanceof PExpr\BitwiseNot) {
            $value = $this->evalExpr($node->expr, $state);

            return new ScalarValue(Expr::unary('~', $value->toExpr()), $value->taint);
        }

        if ($node instanceof PExpr\UnaryMinus) {
            $value = $this->evalExpr($node->expr, $state);

            return new ScalarValue(Expr::unary('-', $value->toExpr()), $value->taint);
        }

        if ($node instanceof PExpr\UnaryPlus) {
            $value = $this->evalExpr($node->expr, $state);

            return new ScalarValue(Expr::unary('+', $value->toExpr()), $value->taint);
        }

        if ($node instanceof PExpr\Cast) {
            return $this->evalCast($node, $state);
        }

        if ($node instanceof PExpr\FuncCall) {
            return $this->evalFuncCall($node, $state);
        }

        if ($node instanceof PExpr\MethodCall || $node instanceof PExpr\NullsafeMethodCall) {
            return $this->evalMethodCall($node, $state);
        }

        if ($node instanceof PExpr\StaticCall) {
            return $this->evalStaticCall($node, $state);
        }

        if ($node instanceof PExpr\New_) {
            return $this->evalNew($node, $state);
        }

        if ($node instanceof PExpr\ArrayDimFetch) {
            // $GLOBALS['x'] 直接映射到全局变量
            if ($node->var instanceof PExpr\Variable && $node->var->name === 'GLOBALS') {
                $key = $node->dim !== null ? $this->evalExpr($node->dim, $state) : null;
                $keyExpr = $key?->toExpr();
                if ($keyExpr !== null && $keyExpr->isConstant() && is_string($keyExpr->constantValue())) {
                    $globalName = '$' . $keyExpr->constantValue();

                    return $state->globals->vars[$globalName]
                        ?? new UnknownValue(ValueFactory::freshName('globals-' . $keyExpr->constantValue()));
                }

                return new UnknownValue(ValueFactory::freshName('globals'));
            }

            $base = $this->evalExpr($node->var, $state);
            $key = $node->dim !== null ? $this->evalExpr($node->dim, $state) : null;

            return $this->readArrayDim($base, $key, $state);
        }

        if ($node instanceof PExpr\Array_) {
            return $this->evalArray($node, $state);
        }

        if ($node instanceof PExpr\PropertyFetch || $node instanceof PExpr\NullsafePropertyFetch) {
            $object = $this->evalExpr($node->var, $state);
            $nameValue = null;
            if ($node->name instanceof Node\Identifier) {
                $name = $node->name->toString();
            } else {
                $nameValue = $this->evalExpr($node->name, $state);
                $nameExpr = Simplifier::simplify($nameValue->toExpr());
                $name = $nameExpr->isConstant() && is_string($nameExpr->constantValue())
                    ? $nameExpr->constantValue()
                    : null;
            }
            if ($name === null) {
                if ($object instanceof ObjectValue) {
                    $properties = array_values($object->properties());
                    $merged = ValueFactory::merge($properties);

                    return new ScalarValue(
                        $merged->toExpr(),
                        $merged->taint->union($object->taint)->union($nameValue?->taint ?? Taint::none()),
                    );
                }

                return new UnknownValue(ValueFactory::freshName('property'), $object->taint);
            }
            if ($object instanceof ObjectValue) {
                return $object->getProperty($name);
            }

            return new UnknownValue(ValueFactory::freshName('property-' . $name), $object->taint);
        }

        if ($node instanceof PExpr\StaticPropertyFetch) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
            $property = $node->name instanceof Node\VarLikeIdentifier ? $node->name->toString() : null;
            if ($class !== null && $property !== null) {
                if (in_array(strtolower($class), ['self', 'static'], true)) {
                    $class = $state->currentClass ?? $class;
                }
                $key = $class . '::' . $property;
                if (isset($this->staticProperties[$key])) {
                    return $this->staticProperties[$key];
                }
            }

            return new UnknownValue(ValueFactory::freshName('static-property'));
        }

        if ($node instanceof PExpr\ConstFetch) {
            return $this->evalConstFetch($node);
        }

        if ($node instanceof PExpr\ClassConstFetch) {
            return $this->evalClassConstFetch($node, $state);
        }

        if ($node instanceof PExpr\Ternary) {
            return $this->evalTernary($node, $state);
        }

        if ($node instanceof PExpr\Isset_) {
            return $this->evalIsset($node, $state);
        }

        if ($node instanceof PExpr\Empty_) {
            $value = $this->evalExpr($node->expr, $state);
            $expr = Simplifier::simplify($value->toExpr());
            if ($expr->isConstant()) {
                return ValueFactory::fromConstant(empty($expr->constantValue()));
            }

            return new ScalarValue(Expr::unary('!', $expr), Taint::none());
        }

        if ($node instanceof PExpr\Include_) {
            return $this->evalInclude($node, $state);
        }

        if ($node instanceof PExpr\Eval_) {
            $code = $this->evalExpr($node->expr, $state);
            $this->checkSink('eval', [$code], $state, $node);

            return new UnknownValue(ValueFactory::freshName('eval-result'), $code->taint);
        }

        if ($node instanceof PExpr\ShellExec) {
            $parts = [];
            $taint = Taint::none();
            foreach ($node->parts as $part) {
                $value = is_string($part)
                    ? ValueFactory::fromConstant($part)
                    : $this->evalExpr($part, $state);
                $parts[] = $value->toExpr();
                $taint = $taint->union($value->taint);
            }
            $combined = $this->concatExprs($parts);
            $this->checkSink('shell_exec', [new ScalarValue($combined, $taint)], $state, $node);

            return new UnknownValue(ValueFactory::freshName('shell-result'), $taint);
        }

        if ($node instanceof PExpr\Print_) {
            $value = $this->evalExpr($node->expr, $state);
            $this->checkSink('print', [$value], $state, $node);

            return ValueFactory::fromConstant(1);
        }

        if ($node instanceof PExpr\Exit_) {
            $value = $node->expr !== null
                ? $this->evalExpr($node->expr, $state)
                : ValueFactory::fromConstant(null);
            $state->terminate(new ExitSignal($value));

            return $value;
        }

        if ($node instanceof PExpr\Throw_) {
            $value = $this->evalExpr($node->expr, $state);
            $state->terminate(new ThrowSignal($value));

            return $value;
        }

        if ($node instanceof PExpr\Closure) {
            $captured = [];
            foreach ($node->uses as $use) {
                $name = $use->var->name;
                if (is_string($name)) {
                    $captured[$name] = $state->get($name);
                }
            }

            return new ClosureValue($node, $captured);
        }

        if ($node instanceof PExpr\ArrowFunction) {
            // 箭头函数自动捕获外层作用域
            return new ClosureValue($node, $state->env);
        }

        if ($node instanceof PExpr\Match_) {
            return $this->evalMatch($node, $state);
        }

        if ($node instanceof PExpr\Instanceof_) {
            return new ScalarValue(new VarExpr(ValueFactory::freshName('instanceof'), Type::Bool), Taint::none());
        }

        if ($node instanceof PExpr\ErrorSuppress) {
            return $this->evalExpr($node->expr, $state);
        }

        if ($node instanceof PExpr\PreInc || $node instanceof PExpr\PostInc
            || $node instanceof PExpr\PreDec || $node instanceof PExpr\PostDec) {
            return $this->evalIncDec($node, $state);
        }

        if ($node instanceof PExpr\Clone_) {
            $value = $this->evalExpr($node->expr, $state);

            return $value instanceof ObjectValue ? clone $value : $value;
        }

        if ($node instanceof PExpr\List_) {
            return ValueFactory::fromConstant(null);
        }

        // 未建模的表达式：返回未知值，但保留子表达式中的污点
        return new UnknownValue(ValueFactory::freshName('expr'), $this->collectTaint($node, $state));
    }

    /**
     * @return list<Expr>
     */
    private function evalArgs(array $args, State $state): array
    {
        $values = [];
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof PExpr\Array_) {
                // 不展开 unpack，按普通数组处理
            }
            $values[] = $this->evalExpr($arg->value, $state);
        }

        return $values;
    }

    /**
     * @return list<Value>
     */
    private function evalArgValues(array $args, State $state): array
    {
        $values = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            if ($arg->unpack) {
                $value = $this->evalExpr($arg->value, $state);
                if ($value instanceof ArrayValue) {
                    foreach ($value->items() as $item) {
                        $values[] = $item;
                    }
                    continue;
                }
            }
            $values[] = $this->evalExpr($arg->value, $state);
        }

        return $values;
    }

    private function evalBinary(PExpr\BinaryOp $node, State $state): Value
    {
        $op = self::binaryOperator($node);
        if ($op === null) {
            return new UnknownValue(ValueFactory::freshName('binary'));
        }

        // ?? 的右值只在左侧为 null 时执行；未知时避免执行右侧副作用
        if ($op === '??') {
            $left = $this->evalExpr($node->left, $state);
            $leftExpr = Simplifier::simplify($left->toExpr());
            if ($leftExpr->isConstant()) {
                return $leftExpr->constantValue() === null
                    ? $this->evalExpr($node->right, $state)
                    : $left;
            }

            return $left;
        }

        if ($op === '&&' || $op === '||') {
            $left = $this->evalExpr($node->left, $state);
            $leftExpr = Simplifier::simplify($left->toExpr());
            if ($leftExpr->isConstant()) {
                if ($op === '&&' && !$leftExpr->constantValue()) {
                    return ValueFactory::fromConstant(false);
                }
                if ($op === '||' && $leftExpr->constantValue()) {
                    return ValueFactory::fromConstant(true);
                }
                $right = $this->evalExpr($node->right, $state);

                return new ScalarValue(
                    Expr::unary('(bool)', $right->toExpr()),
                    Taint::none(),
                );
            }
            $right = $this->evalExpr($node->right, $state);

            return new ScalarValue(
                Expr::bin($op, $leftExpr, $right->toExpr()),
                Taint::none(),
            );
        }

        $left = $this->evalExpr($node->left, $state);
        $right = $this->evalExpr($node->right, $state);

        $taint = $left->taint->union($right->taint);
        if (in_array($op, ['==', '===', '!=', '!==', '<', '<=', '>', '>=', '<=>'], true)) {
            $taint = Taint::none();
        }

        return new ScalarValue(
            Expr::bin($op, $left->toExpr(), $right->toExpr()),
            $taint,
        );
    }

    private static function binaryOperator(PExpr\BinaryOp $node): ?string
    {
        return match (true) {
            $node instanceof PExpr\BinaryOp\Plus => '+',
            $node instanceof PExpr\BinaryOp\Minus => '-',
            $node instanceof PExpr\BinaryOp\Mul => '*',
            $node instanceof PExpr\BinaryOp\Div => '/',
            $node instanceof PExpr\BinaryOp\Mod => '%',
            $node instanceof PExpr\BinaryOp\Pow => '**',
            $node instanceof PExpr\BinaryOp\Concat => '.',
            $node instanceof PExpr\BinaryOp\Equal => '==',
            $node instanceof PExpr\BinaryOp\Identical => '===',
            $node instanceof PExpr\BinaryOp\NotEqual => '!=',
            $node instanceof PExpr\BinaryOp\NotIdentical => '!==',
            $node instanceof PExpr\BinaryOp\Smaller => '<',
            $node instanceof PExpr\BinaryOp\SmallerOrEqual => '<=',
            $node instanceof PExpr\BinaryOp\Greater => '>',
            $node instanceof PExpr\BinaryOp\GreaterOrEqual => '>=',
            $node instanceof PExpr\BinaryOp\BooleanAnd => '&&',
            $node instanceof PExpr\BinaryOp\BooleanOr => '||',
            $node instanceof PExpr\BinaryOp\LogicalAnd => 'and',
            $node instanceof PExpr\BinaryOp\LogicalOr => 'or',
            $node instanceof PExpr\BinaryOp\LogicalXor => 'xor',
            $node instanceof PExpr\BinaryOp\BitwiseAnd => '&',
            $node instanceof PExpr\BinaryOp\BitwiseOr => '|',
            $node instanceof PExpr\BinaryOp\BitwiseXor => '^',
            $node instanceof PExpr\BinaryOp\ShiftLeft => '<<',
            $node instanceof PExpr\BinaryOp\ShiftRight => '>>',
            $node instanceof PExpr\BinaryOp\Coalesce => '??',
            $node instanceof PExpr\BinaryOp\Spaceship => '<=>',
            default => null,
        };
    }

    private function evalAssignOp(PExpr\AssignOp $node, State $state): Value
    {
        $op = match (true) {
            $node instanceof PExpr\AssignOp\Plus => '+',
            $node instanceof PExpr\AssignOp\Minus => '-',
            $node instanceof PExpr\AssignOp\Mul => '*',
            $node instanceof PExpr\AssignOp\Div => '/',
            $node instanceof PExpr\AssignOp\Mod => '%',
            $node instanceof PExpr\AssignOp\Pow => '**',
            $node instanceof PExpr\AssignOp\Concat => '.',
            $node instanceof PExpr\AssignOp\BitwiseAnd => '&',
            $node instanceof PExpr\AssignOp\BitwiseOr => '|',
            $node instanceof PExpr\AssignOp\BitwiseXor => '^',
            $node instanceof PExpr\AssignOp\ShiftLeft => '<<',
            $node instanceof PExpr\AssignOp\ShiftRight => '>>',
            $node instanceof PExpr\AssignOp\Coalesce => '??',
            default => null,
        };
        if ($op === null) {
            return new UnknownValue(ValueFactory::freshName('assign-op'));
        }

        // ??= 只在左侧为 null/未定义时赋值；未知时保守保留原值
        if ($op === '??') {
            $current = $this->readTarget($node->var, $state);
            $currentExpr = Simplifier::simplify($current->toExpr());
            if (!$currentExpr->isConstant()) {
                return $current;
            }
            if ($currentExpr->constantValue() !== null) {
                return $current;
            }
            $right = $this->evalExpr($node->expr, $state);
            $this->assignTo($node->var, $right, $state);

            return $right;
        }

        $current = $this->readTarget($node->var, $state);
        $right = $this->evalExpr($node->expr, $state);
        $result = new ScalarValue(
            Expr::bin($op, $current->toExpr(), $right->toExpr()),
            $current->taint->union($right->taint),
        );
        $this->assignTo($node->var, $result, $state);

        return $result;
    }

    private function evalCast(PExpr\Cast $node, State $state): Value
    {
        $value = $this->evalExpr($node->expr, $state);
        $op = match (true) {
            $node instanceof PExpr\Cast\Int_ => '(int)',
            $node instanceof PExpr\Cast\Double => '(float)',
            $node instanceof PExpr\Cast\String_ => '(string)',
            $node instanceof PExpr\Cast\Bool_ => '(bool)',
            $node instanceof PExpr\Cast\Array_ => '(array)',
            $node instanceof PExpr\Cast\Object_ => '(object)',
            default => null,
        };
        if ($op === null) {
            return $value;
        }

        $taint = $value->taint;
        if (in_array($op, ['(int)', '(float)', '(bool)'], true)) {
            $taint = $taint->sanitizeFor(['ALL']);
        }

        return new ScalarValue(Expr::unary($op, $value->toExpr()), $taint);
    }

    private function evalFuncCall(PExpr\FuncCall $node, State $state): Value
    {
        $args = $this->evalArgValues($node->args, $state);

        if (!$node->name instanceof Node\Name) {
            // 动态函数调用 / 闭包 / 数组 callable
            $callee = $this->evalExpr($node->name, $state);
            $resolved = $this->callCallable($callee, $args, $state, $node);
            if ($resolved !== null) {
                return $resolved;
            }

            return new UnknownValue(
                ValueFactory::freshName('dynamic-call'),
                $callee->taint->union(Taint::merge(array_map(
                    static fn (Value $value): Taint => $value->taint,
                    $args,
                ))),
            );
        }

        return $this->callFunctionByName($node->name->toString(), $args, $state, $node);
    }

    /**
     * @param list<Value> $args
     */
    private function callFunctionByName(string $name, array $args, State $state, Node $node): Value
    {
        $lower = strtolower(ltrim($name, '\\'));

        // Sink 检测
        $sink = SinkCatalog::lookupFunction($lower);
        if ($sink !== null) {
            if ($lower !== 'preg_replace' || $this->pregReplaceHasEvalModifier($args)) {
                $this->checkSink($lower, $args, $state, $node, $sink);
            }
        }

        // 特殊函数
        if ($lower === 'parse_str' && isset($node->args[1], $args[1])) {
            $target = $node->args[1]->value;
            if ($target instanceof PExpr\Variable && is_string($target->name)) {
                $source = $args[0] ?? ValueFactory::unknown();
                $element = new ScalarValue(
                    new VarExpr(ValueFactory::freshName('parse_str-element'), Type::Mixed),
                    $source->taint,
                );
                $state->set($target->name, new ArrayValue([], true, null, null, $source->taint, $element));

                return ValueFactory::fromConstant(null);
            }
        }

        if ($lower === 'extract' && isset($args[0]) && $args[0] instanceof ArrayValue) {
            $count = 0;
            foreach ($args[0]->items() as $key => $value) {
                if (is_string($key) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) === 1) {
                    $state->set('$' . $key, $value);
                    $count++;
                }
            }
            if ($args[0]->isOpen() || $args[0]->sourcePath() !== null) {
                $taint = $args[0]->taint->union($args[0]->elementValue()->taint);
                $state->extractedTaint = ($state->extractedTaint ?? Taint::none())->union($taint);
            }

            return ValueFactory::fromConstant($count);
        }

        if ($lower === 'array_map' && count($args) >= 2) {
            $callback = Simplifier::simplify($args[0]->toExpr());
            if ($callback->isConstant() && is_string($callback->constantValue())) {
                $function = $callback->constantValue();
                $items = [];
                $taint = Taint::none();
                foreach (array_slice($args, 1) as $arrayArg) {
                    if (!$arrayArg instanceof ArrayValue) {
                        continue;
                    }
                    foreach ($arrayArg->items() as $key => $item) {
                        $mapped = $this->callFunctionByName($function, [$item], $state, $node);
                        $items[$key] = $mapped;
                        $taint = $taint->union($mapped->taint);
                    }
                }
                if ($items !== []) {
                    return new ArrayValue($items, false, null, null, $taint);
                }
            }
        }

        if (in_array($lower, ['array_filter', 'array_walk', 'usort', 'uasort', 'uksort', 'array_reduce'], true)
            && isset($args[0], $args[1]) && $args[0] instanceof ArrayValue) {
            $callback = Simplifier::simplify($args[1]->toExpr());
            if ($callback->isConstant() && is_string($callback->constantValue())) {
                $function = $callback->constantValue();
                foreach ($args[0]->items() as $item) {
                    $this->callFunctionByName($function, [$item], $state, $node);
                }

                return $args[0];
            }
        }

        if ($lower === 'preg_replace_callback' && isset($args[1], $args[2])) {
            $callback = Simplifier::simplify($args[1]->toExpr());
            if ($callback->isConstant() && is_string($callback->constantValue())) {
                $this->callFunctionByName($callback->constantValue(), [$args[2]], $state, $node);
            }

            return new ScalarValue(
                Expr::call(
                    $name,
                    array_map(static fn (Value $value): Expr => $value->toExpr(), $args),
                    Type::String,
                ),
                Taint::merge(array_map(static fn (Value $value): Taint => $value->taint, $args)),
            );
        }

        if (in_array($lower, ['call_user_func', 'call_user_func_array'], true) && $args !== []) {
            $callee = $args[0];
            $rest = $lower === 'call_user_func_array'
                ? (isset($args[1]) && $args[1] instanceof ArrayValue ? array_values($args[1]->items()) : [])
                : array_slice($args, 1);
            $resolved = $this->callCallable($callee, $rest, $state, $node);
            if ($resolved !== null) {
                return $resolved;
            }

            return new UnknownValue(ValueFactory::freshName('dynamic-call'), $callee->taint);
        }

        if ($lower === 'define' && isset($args[0], $args[1])) {
            $nameExpr = Simplifier::simplify($args[0]->toExpr());
            if ($nameExpr->isConstant() && is_string($nameExpr->constantValue())) {
                $this->constants[$nameExpr->constantValue()] = $args[1];
            }

            return ValueFactory::fromConstant(true);
        }

        if ($lower === 'func_get_args') {
            return $state->get('__func_args');
        }

        $builtin = $this->builtins->call($name, $args, $state);
        if ($builtin !== null) {
            return $builtin;
        }

        $definition = $this->program->resolveFunction($name, $state->namespace, $state->uses);
        if ($definition !== null) {
            return $this->callFunction($definition, $args, $state, null);
        }

        // 未知函数：保守保留参数污点
        $taint = Taint::merge(array_map(
            static fn (Value $value): Taint => $value->taint,
            $args,
        ));

        return new UnknownValue(ValueFactory::freshName('call-' . $lower), $taint);
    }

    /**
     * 为匿名类构建 ClassDef（仅在当前解释器实例内可见）。
     */
    private function buildAnonymousClass(PStmt\Class_ $node, string $className, string $file): ClassDef
    {
        $methods = [];
        foreach ($node->stmts as $member) {
            if (!$member instanceof PStmt\ClassMethod) {
                continue;
            }
            $methods[strtolower($member->name->toString())] = new FunctionDef(
                $className . '::' . $member->name->toString(),
                $member->params,
                $member->stmts ?? [],
                $file,
                $member->getStartLine(),
                null,
                null,
            );
        }
        $parent = $node->extends?->toString();
        $definition = new ClassDef($className, $parent, $methods, $file, $node->getStartLine());

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

        return new ClassDef($className, $parent, $withClass, $file, $node->getStartLine());
    }

    /**
     * 调用一个 callable 值：闭包、字符串函数名、数组 [obj, method] / [class, method]。
     *
     * @param list<Value> $args
     */
    private function callCallable(Value $callee, array $args, State $state, Node $node): ?Value
    {
        if ($callee instanceof ClosureValue) {
            return $this->callClosure($callee, $args, $state);
        }

        $expr = Simplifier::simplify($callee->toExpr());
        if ($expr->isConstant() && is_string($expr->constantValue())) {
            return $this->callFunctionByName($expr->constantValue(), $args, $state, $node);
        }

        if (!$callee instanceof ArrayValue) {
            return null;
        }

        $items = array_values($callee->items());
        $target = $items[0] ?? null;
        $method = $items[1] ?? null;
        if ($target === null || $method === null) {
            return null;
        }

        $methodExpr = Simplifier::simplify($method->toExpr());
        if (!$methodExpr->isConstant() || !is_string($methodExpr->constantValue())) {
            return null;
        }
        $methodName = $methodExpr->constantValue();

        if ($target instanceof ObjectValue) {
            $definition = $this->resolveMethod($target->class, $methodName);
            if ($definition !== null) {
                return $this->callFunction($definition, $args, $state, $target, $target->class);
            }

            return null;
        }

        $classExpr = Simplifier::simplify($target->toExpr());
        if ($classExpr->isConstant() && is_string($classExpr->constantValue())) {
            $definition = $this->resolveMethod($classExpr->constantValue(), $methodName);
            if ($definition !== null) {
                return $this->callFunction($definition, $args, $state, null, $classExpr->constantValue());
            }
        }

        return null;
    }

    /**
     * 调用闭包/箭头函数。
     *
     * @param list<Value> $args
     */
    private function callClosure(ClosureValue $closure, array $args, State $caller): Value
    {
        $argTaint = Taint::merge(array_map(
            static fn (Value $value): Taint => $value->taint,
            $args,
        ));

        if ($caller->callDepth >= $this->options->maxCallDepth) {
            $this->truncated = true;

            return new UnknownValue(ValueFactory::freshName('closure-depth'), $argTaint);
        }

        $env = [];
        foreach ($closure->captured as $name => $value) {
            $env[str_starts_with($name, '$') ? $name : '$' . $name] = $value;
        }
        if (isset($caller->env['$this'])) {
            $env['$this'] = $caller->env['$this'];
        }

        $params = $closure->node->params ?? [];
        foreach ($params as $index => $param) {
            if (!$param->var instanceof PExpr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $value = $args[$index] ?? ($param->default !== null
                ? $this->evalExpr($param->default, $caller)
                : ValueFactory::fromConstant(null));
            $env['$' . $param->var->name] = $value;
        }

        $state = new State($env, $caller->globals, $caller->constraints, false);
        $state->callDepth = $caller->callDepth + 1;
        $state->file = $caller->file;
        $state->namespace = $caller->namespace;
        $state->uses = $caller->uses;
        $state->includedFiles = $caller->includedFiles;

        if ($closure->node instanceof PExpr\ArrowFunction) {
            return $this->evalExpr($closure->node->expr, $state);
        }

        $results = $this->executeStmts($closure->node->stmts, $state);
        $returnValues = [];
        $allExit = true;
        foreach ($results as $result) {
            if ($result->signal instanceof ReturnSignal) {
                $returnValues[] = $result->signal->value;
                $allExit = false;
            } elseif ($result->signal instanceof ExitSignal) {
                continue;
            } else {
                $returnValues[] = ValueFactory::fromConstant(null);
                $allExit = false;
            }
        }
        if ($returnValues === [] && $allExit) {
            $caller->terminate(new ExitSignal());
        }

        $this->lastThisValue = $state->env['$this'] ?? null;
        $this->syncGlobalsToCaller($caller);

        return $returnValues !== [] ? ValueFactory::merge($returnValues) : ValueFactory::fromConstant(null);
    }

    /**
     * 函数内通过 global 修改的变量需要同步回顶层执行状态。
     */
    private function syncGlobalsToCaller(State $caller): void
    {
        if (!$caller->isGlobalScope) {
            return;
        }
        foreach ($caller->globals->vars as $name => $value) {
            $caller->env[$name] = $value;
        }
    }

    /**
     * @param list<Value> $args
     */
    private function pregReplaceHasEvalModifier(array $args): bool
    {
        if (!isset($args[0])) {
            return true;
        }
        $expr = $args[0]->toExpr();
        if (!$expr->isConstant() || !is_string($expr->constantValue())) {
            return true;
        }
        $pattern = $expr->constantValue();
        $last = strrpos($pattern, $pattern[0] ?? '/');
        if ($last === false) {
            return true;
        }
        $modifiers = substr($pattern, $last + 1);

        return str_contains($modifiers, 'e');
    }

    private function evalMethodCall(PExpr\MethodCall|PExpr\NullsafeMethodCall $node, State $state): Value
    {
        $object = $this->evalExpr($node->var, $state);
        $args = $this->evalArgValues($node->args, $state);

        $method = null;
        if ($node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
        } else {
            $methodValue = $this->evalExpr($node->name, $state);
            $methodExpr = Simplifier::simplify($methodValue->toExpr());
            if ($methodExpr->isConstant() && is_string($methodExpr->constantValue())) {
                $method = $methodExpr->constantValue();
            }
            // 动态方法名整体带污点：保守报告动态调用
            if ($method === null && $methodValue->taint->isTaintedFor('CODE_EXEC')) {
                $this->checkSink('call_user_func', [$methodValue], $state, $node);
            }
        }

        if ($method === null) {
            return new UnknownValue(ValueFactory::freshName('method-call'), $object->taint);
        }

        $sink = SinkCatalog::lookupMethod($method);
        if ($sink !== null) {
            $this->checkSink($method, $args, $state, $node, $sink);
        }

        $lowerMethod = strtolower($method);

        // PDO::quote 按 SQL 类别净化
        if ($lowerMethod === 'quote'
            && $object instanceof ObjectValue
            && str_contains(strtolower($object->class), 'pdo')
            && isset($args[0])) {
            return new ScalarValue($args[0]->toExpr(), $args[0]->taint->sanitizeFor(['SQLI']));
        }

        // 常见框架的请求访问器：$request->input('x') / $this->input->get('x')
        $requestLike = $object instanceof ObjectValue
            && preg_match('/request|input|http/i', $object->class) === 1;
        if (!$requestLike && $node->var instanceof PExpr\PropertyFetch) {
            $propertyName = $node->var->name instanceof Node\Identifier
                ? strtolower($node->var->name->toString())
                : '';
            $requestLike = preg_match('/input|request|query|post|get/', $propertyName) === 1;
        }
        if ($requestLike && in_array($lowerMethod, [
            'input', 'param', 'getparam', 'getqueryparam', 'getpostparam',
            'query', 'post', 'get', 'all',
        ], true)) {
            $key = $args[0] ?? null;
            if ($key !== null) {
                $keyExpr = Simplifier::simplify($key->toExpr());
                if ($keyExpr->isConstant() && is_string($keyExpr->constantValue())) {
                    $parameter = "\$_REQUEST['" . str_replace("'", "\\'", $keyExpr->constantValue()) . "']";

                    return ValueFactory::fromInput($parameter, 'REQUEST');
                }
            }

            return new ArrayValue([], true, '$_REQUEST', 'REQUEST');
        }

        if ($object instanceof ObjectValue) {
            $lowerMethod = strtolower($method);
            if ($lowerMethod === 'getmessage' && $object->hasProperty('message')) {
                return $object->getProperty('message');
            }
            if ($lowerMethod === 'getcode' && $object->hasProperty('code')) {
                return $object->getProperty('code');
            }

            $definition = $this->resolveMethod($object->class, $method);
            if ($definition !== null) {
                $result = $this->callFunction($definition, $args, $state, $object, $object->class);
                // 方法内对 $this 的属性修改写回接收者变量
                if ($this->lastThisValue instanceof ObjectValue && $node->var instanceof PExpr\Variable && is_string($node->var->name)) {
                    $state->set($node->var->name, $this->lastThisValue);
                }

                return $result;
            }
        }

        return new UnknownValue(
            ValueFactory::freshName('method-' . $method),
            $object->taint->union(Taint::merge(array_map(
                static fn (Value $value): Taint => $value->taint,
                $args,
            ))),
        );
    }

    private function evalStaticCall(PExpr\StaticCall $node, State $state): Value
    {
        $args = $this->evalArgValues($node->args, $state);

        $class = null;
        if ($node->class instanceof Node\Name) {
            $class = $node->class->toString();
            $lowerClass = strtolower($class);
            if ($lowerClass === 'self') {
                $class = $state->currentClass ?? $class;
            } elseif ($lowerClass === 'static') {
                $class = $state->currentStaticClass ?? $state->currentClass ?? $class;
            } elseif ($lowerClass === 'parent' && $state->currentClass !== null) {
                $current = $this->program->resolveClass($state->currentClass);
                $class = $current?->parent ?? $class;
            }
        } else {
            $classValue = $this->evalExpr($node->class, $state);
            $classExpr = $classValue->toExpr();
            if ($classExpr->isConstant() && is_string($classExpr->constantValue())) {
                $class = $classExpr->constantValue();
            }
        }

        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

        if ($class === null || $method === null) {
            return new UnknownValue(ValueFactory::freshName('static-call'));
        }

        $sink = SinkCatalog::lookupMethod($method);
        if ($sink !== null) {
            $this->checkSink($method, $args, $state, $node, $sink);
        }

        $definition = $this->resolveMethod($class, $method);
        if ($definition !== null) {
            return $this->callFunction($definition, $args, $state, null, $class);
        }

        return new UnknownValue(
            ValueFactory::freshName('static-' . $method),
            Taint::merge(array_map(static fn (Value $value): Taint => $value->taint, $args)),
        );
    }

    private function evalNew(PExpr\New_ $node, State $state): Value
    {
        $args = $this->evalArgValues($node->args, $state);
        $class = 'stdClass';
        if ($node->class instanceof Node\Name) {
            $class = $node->class->toString();
        } elseif ($node->class instanceof PStmt\Class_) {
            $class = 'anonymous@' . $node->class->getStartLine() . '#' . spl_object_id($node->class);
            $this->anonymousClasses[strtolower($class)] = $this->buildAnonymousClass($node->class, $class, $state->file);
        } else {
            $classValue = $this->evalExpr($node->class, $state);
            $classExpr = Simplifier::simplify($classValue->toExpr());
            if ($classExpr->isConstant() && is_string($classExpr->constantValue())) {
                $class = $classExpr->constantValue();
            }
        }
        $lowerClass = strtolower($class);
        $properties = [];
        // 内置异常/错误：第一个参数是 message，便于建模 catch 后的 getMessage()
        if (isset($args[0]) && (str_contains($lowerClass, 'exception') || str_contains($lowerClass, 'error'))) {
            $properties['message'] = $args[0];
        }
        $object = new ObjectValue($class, $properties, Taint::merge(array_map(
            static fn (Value $value): Taint => $value->taint,
            $args,
        )));

        $constructor = $this->resolveMethod($class, '__construct');
        if ($constructor !== null) {
            $this->callFunction($constructor, $args, $state, $object, $class);
            if ($this->lastThisValue instanceof ObjectValue) {
                $object = $this->lastThisValue;
            }
        }

        return $object;
    }

    private function evalArray(PExpr\Array_ $node, State $state): Value
    {
        $items = [];
        $taint = Taint::none();
        foreach ($node->items as $item) {
            if ($item === null) {
                continue;
            }
            $value = $this->evalExpr($item->value, $state);
            $taint = $taint->union($value->taint);
            if ($item->key === null) {
                $items[] = $value;
                continue;
            }
            $key = $this->evalExpr($item->key, $state);
            $keyExpr = Simplifier::simplify($key->toExpr());
            if ($keyExpr->isConstant() && (is_int($keyExpr->constantValue()) || is_string($keyExpr->constantValue()))) {
                $items[$keyExpr->constantValue()] = $value;
            }
        }

        return new ArrayValue($items, false, null, null, $taint);
    }

    private function readArrayDim(Value $base, ?Value $key, State $state): Value
    {
        if ($base instanceof ArrayValue) {
            if ($key === null) {
                return $base->getDynamic(new VarExpr(ValueFactory::freshName('append')));
            }
            $keyExpr = Simplifier::simplify($key->toExpr());
            if ($keyExpr->isConstant() && (is_int($keyExpr->constantValue()) || is_string($keyExpr->constantValue()))) {
                return $base->get($keyExpr->constantValue());
            }

            return $base->getDynamic($keyExpr);
        }

        // 输入值/未知值的二维访问：生成新的输入表达式
        $baseExpr = $base->toExpr();
        if ($baseExpr instanceof VarExpr) {
            $keyText = $key?->toExpr()->toString() ?? '';
            if ($key !== null && $key->toExpr()->isConstant() && is_string($key->toExpr()->constantValue())) {
                $keyText = "'" . str_replace("'", "\\'", $key->toExpr()->constantValue()) . "'";
            } elseif ($key !== null && $key->toExpr()->isConstant()) {
                $keyText = (string) $key->toExpr()->constantValue();
            }
            $expression = $baseExpr->name . '[' . $keyText . ']';
            $taint = $base->taint;
            $kind = \PopChain\Symbolic\Security\SourceCatalog::kindFromExpression($expression);
            if ($kind !== 'UNKNOWN') {
                $taint = $taint->union(Taint::of(new \PopChain\Symbolic\Symbolic\TaintSource($kind, $expression)));
            }

            return new ScalarValue(new VarExpr($expression, Type::Mixed), $taint);
        }

        return new UnknownValue(ValueFactory::freshName('array-item'), $base->taint);
    }

    private function evalConstFetch(PExpr\ConstFetch $node): Value
    {
        $name = $node->name->toString();
        $lower = strtolower($name);
        if ($lower === 'true') {
            return ValueFactory::fromConstant(true);
        }
        if ($lower === 'false') {
            return ValueFactory::fromConstant(false);
        }
        if ($lower === 'null') {
            return ValueFactory::fromConstant(null);
        }
        if (isset($this->constants[$name])) {
            return $this->constants[$name];
        }
        if (defined($name)) {
            try {
                $value = constant($name);
                if (is_scalar($value) || is_array($value)) {
                    return ValueFactory::fromConstant($value);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return new ScalarValue(new VarExpr($name, Type::Mixed), Taint::none());
    }

    private function evalClassConstFetch(PExpr\ClassConstFetch $node, State $state): Value
    {
        $const = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
        if ($const === null) {
            return new UnknownValue(ValueFactory::freshName('class-const'));
        }
        if (strtolower($const) === 'class') {
            return ValueFactory::fromConstant($class ?? 'unknown');
        }

        return new UnknownValue(ValueFactory::freshName('class-const-' . $const));
    }

    private function evalTernary(PExpr\Ternary $node, State $state): Value
    {
        $cond = $this->evalExpr($node->cond, $state);
        $condExpr = Simplifier::simplify($cond->toExpr());

        if ($condExpr->isConstant()) {
            return $condExpr->constantValue()
                ? $this->evalExpr($node->if ?? $node->cond, $state)
                : $this->evalExpr($node->else, $state);
        }

        // 短三元 $a ?: $b 在 $a 未知时不执行 else，避免副作用误报
        if ($node->if === null) {
            return $cond;
        }

        $ifValue = $this->evalExpr($node->if, $state);
        $elseValue = $this->evalExpr($node->else, $state);

        return new ScalarValue(
            Expr::ternary($condExpr, $ifValue->toExpr(), $elseValue->toExpr()),
            $ifValue->taint->union($elseValue->taint),
        );
    }

    private function evalIsset(PExpr\Isset_ $node, State $state): Value
    {
        $allKnown = true;
        $result = true;
        $expressions = [];

        foreach ($node->vars as $var) {
            if ($var instanceof PExpr\Variable && is_string($var->name)) {
                $expressions[] = new VarExpr('$' . $var->name, Type::Mixed);
                $exists = $state->has($var->name);
                $result = $result && $exists;
                continue;
            }
            if ($var instanceof PExpr\ArrayDimFetch) {
                $base = $this->evalExpr($var->var, $state);
                $key = $var->dim !== null ? $this->evalExpr($var->dim, $state) : null;
                $expressions[] = Expr::arrayAccess($base->toExpr(), $key?->toExpr());
                if ($base instanceof ArrayValue && $key !== null && $key->toExpr()->isConstant()) {
                    $k = $key->toExpr()->constantValue();
                    if (is_int($k) || is_string($k)) {
                        if ($base->hasKey($k)) {
                            continue;
                        }
                        if ($base->isOpen() || $base->sourcePath() !== null) {
                            // 抽象数组可能包含未知键，保守地视为未知
                            $allKnown = false;
                            continue;
                        }
                        $result = false;
                        continue;
                    }
                }
            }
            $allKnown = false;
            $expressions[] = new VarExpr(ValueFactory::freshName('isset'), Type::Mixed);
        }

        if ($allKnown) {
            return ValueFactory::fromConstant($result);
        }

        return new ScalarValue(
            new \PopChain\Symbolic\Expr\CallExpr('isset', $expressions, Type::Bool),
            Taint::none(),
        );
    }

    private function evalInclude(PExpr\Include_ $node, State $state): Value
    {
        $pathValue = $this->evalExpr($node->expr, $state);
        $pathExpr = Simplifier::simplify($pathValue->toExpr());
        $path = $pathExpr->isConstant() && is_string($pathExpr->constantValue())
            ? $pathExpr->constantValue()
            : null;

        $this->checkSink('include', [$pathValue], $state, $node);

        if ($path === null || !$this->options->followIncludes) {
            return new UnknownValue(ValueFactory::freshName('include-result'), $pathValue->taint);
        }

        $resolved = $this->resolveIncludePath($path, $state->file);
        if ($resolved === null || isset($state->includedFiles[$resolved])) {
            return ValueFactory::fromConstant(false);
        }

        $state->includedFiles[$resolved] = true;

        $previousFile = $state->file;
        $previousNamespace = $state->namespace;
        $previousUses = $state->uses;

        $states = $this->executeFile($resolved, $state);

        $state->file = $previousFile;
        $state->namespace = $previousNamespace;
        $state->uses = $previousUses;

        $returnValues = [];
        foreach ($states as $result) {
            $result->file = $previousFile;
            $result->namespace = $previousNamespace;
            $result->uses = $previousUses;
            if ($result->signal instanceof ReturnSignal) {
                $returnValues[] = $result->signal->value;
                $result->signal = null;
            }
            if ($result->signal instanceof ExitSignal) {
                $state->terminate($result->signal);
            }
        }

        return $returnValues !== [] ? ValueFactory::merge($returnValues) : ValueFactory::fromConstant(1);
    }

    private function evalMatch(PExpr\Match_ $node, State $state): Value
    {
        $cond = $this->evalExpr($node->cond, $state);
        $condExpr = Simplifier::simplify($cond->toExpr());

        if ($condExpr->isConstant()) {
            foreach ($node->arms as $arm) {
                if ($arm->conds === null) {
                    return $this->evalExpr($arm->body, $state);
                }
                foreach ($arm->conds as $armCond) {
                    $value = $this->evalExpr($armCond, $state);
                    $comparison = Simplifier::simplify(Expr::bin('===', $condExpr, $value->toExpr()));
                    if ($comparison->isConstant() && $comparison->constantValue()) {
                        return $this->evalExpr($arm->body, $state);
                    }
                }
            }

            return ValueFactory::fromConstant(null);
        }

        $values = [];
        foreach ($node->arms as $arm) {
            $values[] = $this->evalExpr($arm->body, $state);
        }

        return $values !== [] ? ValueFactory::merge($values) : ValueFactory::fromConstant(null);
    }

    private function evalIncDec(PExpr\PreInc|PExpr\PostInc|PExpr\PreDec|PExpr\PostDec $node, State $state): Value
    {
        $current = $this->readTarget($node->var, $state);
        $op = $node instanceof PExpr\PreInc || $node instanceof PExpr\PostInc ? '+' : '-';
        $updated = new ScalarValue(
            Expr::bin($op, $current->toExpr(), Expr::literal(1)),
            $current->taint,
        );
        $this->assignTo($node->var, $updated, $state);

        return $node instanceof PExpr\PostInc || $node instanceof PExpr\PostDec ? $current : $updated;
    }

    private function evalMagicConst(Node\Scalar\MagicConst $node, State $state): Value
    {
        return match (true) {
            $node instanceof Node\Scalar\MagicConst\File => ValueFactory::fromConstant($state->file),
            $node instanceof Node\Scalar\MagicConst\Dir => ValueFactory::fromConstant(dirname($state->file)),
            $node instanceof Node\Scalar\MagicConst\Line => ValueFactory::fromConstant($node->getStartLine()),
            $node instanceof Node\Scalar\MagicConst\Function_ => ValueFactory::fromConstant('function'),
            $node instanceof Node\Scalar\MagicConst\Class_ => ValueFactory::fromConstant('class'),
            $node instanceof Node\Scalar\MagicConst\Method => ValueFactory::fromConstant('method'),
            $node instanceof Node\Scalar\MagicConst\Namespace_ => ValueFactory::fromConstant((string) $state->namespace),
            $node instanceof Node\Scalar\MagicConst\Trait_ => ValueFactory::fromConstant('trait'),
            default => new UnknownValue(ValueFactory::freshName('magic-const')),
        };
    }

    private function evalInterpolated(Node\Scalar\InterpolatedString $node, State $state): Value
    {
        $parts = [];
        $taint = Taint::none();

        foreach ($node->parts as $part) {
            if (is_string($part)) {
                $parts[] = Expr::literal($part);
                continue;
            }
            if ($part instanceof Node\Scalar\InterpolatedStringPart) {
                $parts[] = Expr::literal($part->value);
                continue;
            }
            $value = $this->evalExpr($part, $state);
            $parts[] = $value->toExpr();
            $taint = $taint->union($value->taint);
        }

        return new ScalarValue($this->concatExprs($parts), $taint);
    }

    /**
     * @param list<Expr> $parts
     */
    private function concatExprs(array $parts): Expr
    {
        if ($parts === []) {
            return Expr::literal('');
        }

        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result = Expr::bin('.', $result, $part);
        }

        return $result;
    }

    // ==================================================================
    // 赋值 / 读取
    // ==================================================================

    private function assignTo(Node $target, Value $value, State $state): void
    {
        if ($target instanceof PExpr\Variable) {
            if (is_string($target->name)) {
                $state->set($target->name, $value);
            }

            return;
        }

        if ($target instanceof PExpr\ArrayDimFetch) {
            $this->assignArrayPath($target, $value, $state);

            return;
        }

        if ($target instanceof PExpr\PropertyFetch) {
            $this->assignPropertyPath($target, $value, $state);

            return;
        }

        if ($target instanceof PExpr\StaticPropertyFetch) {
            $class = $target->class instanceof Node\Name ? $target->class->toString() : null;
            $property = $target->name instanceof Node\VarLikeIdentifier ? $target->name->toString() : null;
            if ($class !== null && $property !== null) {
                if (in_array(strtolower($class), ['self', 'static'], true)) {
                    $class = $state->currentClass ?? $class;
                }
                $this->staticProperties[$class . '::' . $property] = $value;
            }

            return;
        }

        if ($target instanceof PExpr\List_ || $target instanceof PExpr\Array_) {
            $items = $target->items;
            $index = 0;
            foreach ($items as $item) {
                if ($item === null) {
                    $index++;
                    continue;
                }

                $lookup = $index;
                if ($item->key !== null) {
                    $keyValue = $this->evalExpr($item->key, $state);
                    $keyExpr = Simplifier::simplify($keyValue->toExpr());
                    if ($keyExpr->isConstant() && (is_int($keyExpr->constantValue()) || is_string($keyExpr->constantValue()))) {
                        $lookup = $keyExpr->constantValue();
                    }
                }

                $itemValue = $value instanceof ArrayValue
                    ? $value->get($lookup)
                    : new UnknownValue(ValueFactory::freshName('destructure'), $value->taint);
                $this->assignTo($item->value, $itemValue, $state);
                $index++;
            }
        }
    }

    private function assignArrayPath(PExpr\ArrayDimFetch $target, Value $value, State $state): void
    {
        // $GLOBALS['x'] = ...
        if ($target->var instanceof PExpr\Variable && $target->var->name === 'GLOBALS' && $target->dim !== null) {
            $key = $this->evalExpr($target->dim, $state);
            $keyExpr = Simplifier::simplify($key->toExpr());
            if ($keyExpr->isConstant() && is_string($keyExpr->constantValue())) {
                $globalName = '$' . $keyExpr->constantValue();
                $state->globals->vars[$globalName] = $value;
                $state->env[$globalName] = $value;
            }

            return;
        }

        $keys = [];
        $current = $target;
        while ($current instanceof PExpr\ArrayDimFetch) {
            $keys[] = $current->dim;
            $current = $current->var;
        }
        if (!$current instanceof PExpr\Variable || !is_string($current->name)) {
            return;
        }

        $keys = array_reverse($keys);
        $base = $state->get($current->name);
        $state->set($current->name, $this->setPath($base, $keys, $value, $state));
    }

    /**
     * @param list<Node|null> $keys
     */
    private function setPath(Value $base, array $keys, Value $value, State $state): Value
    {
        if ($keys === []) {
            return $value;
        }

        $keyNode = array_shift($keys);
        if ($keyNode === null) {
            return $base instanceof ArrayValue ? $base->append($value) : new ArrayValue([$value], false, null, null, $value->taint);
        }

        $keyValue = $this->evalExpr($keyNode, $state);
        $keyExpr = Simplifier::simplify($keyValue->toExpr());
        if (!$keyExpr->isConstant() || (!is_int($keyExpr->constantValue()) && !is_string($keyExpr->constantValue()))) {
            // 动态键：把值合并到"未知元素"槽，后续动态读取仍能拿到污点
            $array = $base instanceof ArrayValue
                ? $base
                : new ArrayValue([], true, null, null, $base->taint);
            $element = $array->elementValue()->merge($value);

            return new ArrayValue(
                $array->items(),
                true,
                null,
                null,
                $array->taint->union($value->taint),
                $element,
            );
        }
        $key = $keyExpr->constantValue();

        $array = $base instanceof ArrayValue
            ? $base
            : new ArrayValue([], true, null, null, $base->taint);
        $child = $array->hasKey($key) ? $array->get($key) : new UnknownValue(ValueFactory::freshName('array-child'));

        return $array->withKey($key, $this->setPath($child, $keys, $value, $state));
    }

    /**
     * 处理属性链赋值：$a->b->c = $value。
     */
    private function assignPropertyPath(PExpr\PropertyFetch $target, Value $value, State $state): void
    {
        $names = [];
        $current = $target;
        while ($current instanceof PExpr\PropertyFetch) {
            if (!$current->name instanceof Node\Identifier) {
                return;
            }
            $names[] = $current->name->toString();
            $current = $current->var;
        }
        if (!$current instanceof PExpr\Variable || !is_string($current->name)) {
            return;
        }

        $names = array_reverse($names);
        $state->set($current->name, $this->setPropertyPath($state->get($current->name), $names, $value));
    }

    /**
     * @param list<string> $names
     */
    private function setPropertyPath(Value $object, array $names, Value $value): Value
    {
        if ($names === []) {
            return $value;
        }
        if (!$object instanceof ObjectValue) {
            return $object;
        }
        $name = array_shift($names);

        return $object->withProperty($name, $this->setPropertyPath($object->getProperty($name), $names, $value));
    }

    private function readTarget(Node $target, State $state): Value
    {
        if ($target instanceof PExpr\Variable) {
            return is_string($target->name) ? $state->get($target->name) : ValueFactory::unknown();
        }
        if ($target instanceof PExpr\ArrayDimFetch) {
            $base = $this->evalExpr($target->var, $state);
            $key = $target->dim !== null ? $this->evalExpr($target->dim, $state) : null;

            return $this->readArrayDim($base, $key, $state);
        }
        if ($target instanceof PExpr\PropertyFetch) {
            $object = $this->evalExpr($target->var, $state);
            $name = $target->name instanceof Node\Identifier ? $target->name->toString() : null;
            if ($object instanceof ObjectValue && $name !== null) {
                return $object->getProperty($name);
            }
        }

        return ValueFactory::unknown();
    }

    private function unsetArrayPath(PExpr\ArrayDimFetch $target, State $state): void
    {
        if (!$target->var instanceof PExpr\Variable || !is_string($target->var->name) || $target->dim === null) {
            return;
        }
        $base = $state->get($target->var->name);
        $key = $this->evalExpr($target->dim, $state);
        $keyExpr = Simplifier::simplify($key->toExpr());
        if ($base instanceof ArrayValue && $keyExpr->isConstant()) {
            $value = $keyExpr->constantValue();
            if (is_int($value) || is_string($value)) {
                $state->set($target->var->name, $base->withKeyRemoved($value));
            }
        }
    }

    // ==================================================================
    // 函数 / 方法调用
    // ==================================================================

    /**
     * @param list<Value> $args
     */
    private function callFunction(FunctionDef $def, array $args, State $caller, ?ObjectValue $thisValue, ?string $calledClass = null): Value
    {
        $argTaint = Taint::merge(array_map(
            static fn (Value $value): Taint => $value->taint,
            $args,
        ));

        if ($caller->callDepth >= $this->options->maxCallDepth) {
            $this->truncated = true;

            return new UnknownValue(ValueFactory::freshName('call-depth'), $argTaint);
        }
        if ($this->timedOut()) {
            $this->truncated = true;

            return new UnknownValue(ValueFactory::freshName('timeout'), $argTaint);
        }

        $env = [];
        foreach ($caller->globals->vars as $name => $value) {
            if (str_starts_with($name, '$_') || $name === '$GLOBALS') {
                $env[$name] = $value;
            }
        }
        $env['$__func_args'] = new ArrayValue($args, false, null, null, $argTaint);

        foreach ($def->params as $index => $param) {
            if (!$param->var instanceof PExpr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $name = '$' . $param->var->name;
            $value = $args[$index] ?? null;
            if ($value === null) {
                $value = $param->default !== null
                    ? $this->evalExpr($param->default, $caller)
                    : ValueFactory::fromConstant(null);
            }
            $env[$name] = $value;

            // PHP 8 构造器属性提升
            if ($thisValue !== null && $param->flags !== 0) {
                $thisValue = $thisValue->withProperty($param->var->name, $value);
            }
        }

        if ($thisValue !== null) {
            $env['$this'] = $thisValue;
        }

        $callState = new State($env, $caller->globals, $caller->constraints, false);
        $callState->callDepth = $caller->callDepth + 1;
        $callState->file = $def->file;
        $callState->namespace = $def->namespace;
        $callState->currentClass = $def->class?->name;
        $callState->currentStaticClass = $calledClass ?? $thisValue?->class ?? $def->class?->name;
        $callState->uses = $this->program->fileUses($def->file);
        $callState->includedFiles = $caller->includedFiles;

        $results = $this->executeStmts($def->stmts, $callState);

        $returnValues = [];
        $allExit = true;
        foreach ($results as $result) {
            if ($result->signal instanceof ReturnSignal) {
                $returnValues[] = $result->signal->value;
                $allExit = false;
            } elseif ($result->signal instanceof ExitSignal) {
                continue;
            } else {
                $returnValues[] = ValueFactory::fromConstant(null);
                $allExit = false;
            }
        }

        if ($returnValues === [] && $allExit) {
            $caller->terminate(new ExitSignal());
        }

        $this->lastThisValue = $callState->env['$this'] ?? null;
        $this->syncGlobalsToCaller($caller);

        return $returnValues !== [] ? ValueFactory::merge($returnValues) : ValueFactory::fromConstant(null);
    }

    private function resolveMethod(string $className, string $method): ?FunctionDef
    {
        $lower = strtolower($className);
        if (isset($this->anonymousClasses[$lower])) {
            $anonymous = $this->anonymousClasses[$lower];
            $definition = $anonymous->method($method);
            if ($definition !== null) {
                return $definition;
            }
            if ($anonymous->parent !== null) {
                $parent = $this->resolveMethod($anonymous->parent, $method);
                if ($parent !== null) {
                    return $parent;
                }
            }
        }

        $class = $this->program->resolveClass($className);
        $visitedTraits = [];
        while ($class !== null) {
            $definition = $class->method($method);
            if ($definition !== null) {
                return $definition;
            }
            foreach ($class->traits as $traitName) {
                $trait = $this->program->resolveClass($traitName);
                if ($trait === null || isset($visitedTraits[$trait->name])) {
                    continue;
                }
                $visitedTraits[$trait->name] = true;
                $traitMethod = $trait->method($method);
                if ($traitMethod !== null) {
                    return $traitMethod;
                }
            }
            $class = $class->parent !== null ? $this->program->resolveClass($class->parent) : null;
        }

        return null;
    }

    // ==================================================================
    // Sink 检测
    // ==================================================================

    /**
     * @param list<Value> $values
     */
    private function checkSink(string $name, array $values, State $state, Node $node, ?Sink $sink = null): void
    {
        $sink ??= SinkCatalog::lookupFunction($name);
        if ($sink === null) {
            return;
        }

        $candidates = $values;
        if ($sink->argIndexes !== []) {
            $candidates = [];
            foreach ($sink->argIndexes as $index) {
                if (isset($values[$index])) {
                    $candidates[] = $values[$index];
                }
            }
            if ($candidates === []) {
                $candidates = $values;
            }
        }

        $sources = [];
        foreach ($candidates as $value) {
            if ($value->taint->isTaintedFor($sink->category)) {
                foreach ($value->taint->describe() as $description) {
                    $sources[] = $description;
                }
            }
        }

        if ($sources === []) {
            return;
        }

        $path = [];
        foreach ($state->constraints as $constraint) {
            $path[] = $constraint->toString();
        }

        $this->findings->add(new Finding(
            $sink->category,
            $sink->severity,
            $sink->title,
            $state->file,
            $node->getStartLine(),
            $this->sinkDisplayName($name, $sink),
            array_values(array_unique($sources)),
            $path,
            $sink->payload ?? '',
            $this->snippet($state->file, $node->getStartLine()),
        ));
    }

    private function sinkDisplayName(string $name, Sink $sink): string
    {
        return $name . '()';
    }

    // ==================================================================
    // 辅助
    // ==================================================================

    private function constantValue(Node $node): mixed
    {
        $value = $this->evalExpr($node, new State([], new GlobalScope()));

        return $value->toExpr()->isConstant() ? $value->toExpr()->constantValue() : 1;
    }

    /**
     * @param list<State> $states
     * @return list<State>
     */
    private function limitStates(array $states): array
    {
        if (count($states) > $this->options->maxStates) {
            $this->truncated = true;

            return array_slice($states, 0, $this->options->maxStates);
        }

        return $states;
    }

    private function timedOut(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function resolveIncludePath(string $path, string $currentFile): ?string
    {
        if ($path === '') {
            return null;
        }

        $candidates = [];
        if (str_starts_with($path, '/')) {
            $candidates[] = $path;
        } else {
            $candidates[] = dirname($currentFile) . '/' . $path;
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePath($candidate);
            if ($this->program->statements($normalized) !== null) {
                return $normalized;
            }
            if (is_file($normalized)) {
                $real = realpath($normalized);
                if ($real !== false && $this->program->statements($real) !== null) {
                    return $real;
                }
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /**
     * 收集一个未建模表达式内部可能出现的污点（保守兜底）。
     */
    private function collectTaint(Node $node, State $state): Taint
    {
        $taint = Taint::none();
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof PExpr\Variable && is_string($child->name)) {
                $taint = $taint->union($state->get($child->name)->taint);
            } elseif ($child instanceof Node) {
                $taint = $taint->union($this->collectTaint($child, $state));
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $taint = $taint->union($this->collectTaint($item, $state));
                    }
                }
            }
        }

        return $taint;
    }

    private function snippet(string $file, int $line): string
    {
        if (!isset($this->lineCache[$file])) {
            $lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
            $this->lineCache[$file] = $lines === false ? [] : $lines;
        }

        return $this->lineCache[$file][$line - 1] ?? '';
    }
}
