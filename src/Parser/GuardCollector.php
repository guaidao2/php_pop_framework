<?php
declare(strict_types=1);

namespace PopChain\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * 收集方法里"要走到会执行动作的代码，必须先满足的属性条件"。
 *
 * 覆盖常见的守卫写法：
 *   if ($this->is_admin === true)         is_admin = true
 *   if ($this->token === 'admin_token')    token = 'admin_token'
 *   if ($this->ttl <= 0) { return; }       ttl = 1（后面的代码要求条件不成立）
 *   if (!isset($this->obj)) { return; }    obj = ''（非 null）
 *   if (empty($this->arr)) { return; }     arr = 'x'（非空）
 *   if ($this->flag) / if (!$this->flag)
 *
 * 只做字面量/类型级别的约束，复杂表达式（函数返回值、算术、正则）不建模。
 */
final class GuardCollector
{
    /** @var array<string, mixed> */
    private array $requirements = [];

    /** @var list<array{expr:string,expected:bool}> */
    private array $conditions = [];

    private NodeFinder $finder;

    private \PhpParser\PrettyPrinter\Standard $printer;

    public function __construct()
    {
        $this->finder = new NodeFinder();
        $this->printer = new \PhpParser\PrettyPrinter\Standard();
    }

    /**
     * @param array<Node>|null $stmts
     * @return array<string, mixed>
     */
    public function collect(?array $stmts): array
    {
        $this->requirements = [];
        if ($stmts !== null) {
            $this->walk($stmts);
        }
        return $this->requirements;
    }

    /** @return list<array{expr:string,expected:bool}> 原始条件表达式，供求解器使用 */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** @param array<Node> $stmts */
    private function walk(array $stmts): void
    {
        /** @var Node|null $pending 早退守卫的条件：走到后面的代码说明它为假 */
        $pending = null;

        foreach ($stmts as $stmt) {
            // 后面的代码真会被执行时，早退守卫必须不成立
            if ($pending !== null && $this->stmtMatters($stmt)) {
                $this->applyCondition($pending, false);
                $pending = null;
            }

            if ($stmt instanceof Stmt\If_) {
                $this->handleIf($stmt);
                if ($this->alwaysTerminates($stmt->stmts)
                    && $stmt->else === null
                    && $stmt->elseifs === []) {
                    $pending = $stmt->cond;
                }
                continue;
            }

            if ($stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Throw_
                || $stmt instanceof Stmt\Break_ || $stmt instanceof Stmt\Continue_) {
                break;
            }

            if ($stmt instanceof Stmt\Foreach_ || $stmt instanceof Stmt\While_
                || $stmt instanceof Stmt\Do_ || $stmt instanceof Stmt\For_) {
                $this->walk($stmt->stmts);
                continue;
            }

            if ($stmt instanceof Stmt\TryCatch_) {
                $children = $stmt->stmts;
                foreach ($stmt->catches as $catch) {
                    $children = array_merge($children, $catch->stmts);
                }
                $this->walk($children);
                continue;
            }

            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Ternary) {
                $this->applyCondition($stmt->expr->cond, true);
            }
        }
    }

    private function handleIf(Stmt\If_ $stmt): void
    {
        $thenMatters = $this->branchMatters($stmt->stmts);
        $elseMatters = $stmt->else !== null && $this->branchMatters($stmt->else->stmts);

        if ($thenMatters) {
            $this->applyCondition($stmt->cond, true);
        }
        if ($elseMatters) {
            $this->applyCondition($stmt->cond, false);
        }

        foreach ($stmt->elseifs as $elseif) {
            if ($this->branchMatters($elseif->stmts)) {
                $this->applyCondition($elseif->cond, true);
            }
        }

        $this->walk($stmt->stmts);
        if ($stmt->else !== null) {
            $this->walk($stmt->else->stmts);
        }
    }

    /** 语句里是否有真正会执行的动作（调用、输出、字符串上下文、属性读取）。 */
    private function stmtMatters(Node $stmt): bool
    {
        if ($stmt instanceof Stmt\If_) {
            if ($this->branchMatters($stmt->stmts)) {
                return true;
            }
            if ($stmt->else !== null && $this->branchMatters($stmt->else->stmts)) {
                return true;
            }
            foreach ($stmt->elseifs as $elseif) {
                if ($this->branchMatters($elseif->stmts)) {
                    return true;
                }
            }
            return $this->branchMatters([$stmt->cond]);
        }

        return $this->branchMatters([$stmt]);
    }

    private function applyCondition(Node $cond, bool $expected): void
    {
        if ($cond instanceof Expr\BinaryOp || $cond instanceof Expr\BooleanNot
            || $cond instanceof Expr\FuncCall || $cond instanceof Expr\Isset_
            || $cond instanceof Expr\Empty_ || $this->isThisProperty($cond)) {
            $this->conditions[] = [
                'expr' => $this->printer->prettyPrintExpr($cond),
                'expected' => $expected,
            ];
        }

        if ($cond instanceof Expr\BooleanNot) {
            $this->applyCondition($cond->expr, !$expected);
            return;
        }

        if ($cond instanceof Expr\BinaryOp\BooleanAnd || $cond instanceof Expr\BinaryOp\LogicalAnd) {
            if (!$expected) {
                return;
            }
            $this->applyCondition($cond->left, true);
            $this->applyCondition($cond->right, true);
            return;
        }

        if ($cond instanceof Expr\BinaryOp\BooleanOr || $cond instanceof Expr\BinaryOp\LogicalOr) {
            if ($expected) {
                return;
            }
            $this->applyCondition($cond->left, false);
            $this->applyCondition($cond->right, false);
            return;
        }

        if ($cond instanceof Expr\BinaryOp\Smaller || $cond instanceof Expr\BinaryOp\SmallerOrEqual
            || $cond instanceof Expr\BinaryOp\Greater || $cond instanceof Expr\BinaryOp\GreaterOrEqual
            || $cond instanceof Expr\BinaryOp\Identical || $cond instanceof Expr\BinaryOp\NotIdentical
            || $cond instanceof Expr\BinaryOp\Equal || $cond instanceof Expr\BinaryOp\NotEqual) {
            $this->applyComparison($cond, $expected);
            return;
        }

        if ($cond instanceof Expr\FuncCall && $cond->name instanceof Node\Name) {
            $this->applyTypeGuard(strtolower($cond->name->toString()), $cond->args, $expected);
            return;
        }

        // isset()/empty() 在 AST 里是独立节点，不是普通函数调用
        if ($cond instanceof Expr\Isset_) {
            foreach ($cond->vars as $var) {
                if ($var instanceof Expr\PropertyFetch && $this->isThisProperty($var)) {
                    $this->requirements[$this->propertyName($var)] = $expected ? '' : null;
                }
            }
            return;
        }

        if ($cond instanceof Expr\Empty_) {
            if ($cond->expr instanceof Expr\PropertyFetch && $this->isThisProperty($cond->expr)) {
                $this->requirements[$this->propertyName($cond->expr)] = $expected ? '' : 'x';
            }
            return;
        }

        if ($this->isThisProperty($cond)) {
            $this->requirements[$this->propertyName($cond)] = $expected ? 'x' : '';
        }
    }

    private function applyComparison(Expr\BinaryOp $node, bool $expected): void
    {
        $pairs = [[$node->left, $node->right], [$node->right, $node->left]];
        foreach ($pairs as [$propertyNode, $literalNode]) {
            if (!$this->isThisProperty($propertyNode)) {
                continue;
            }

            $isNullLiteral = $this->literalNodeIsNull($literalNode);
            $literal = $this->literalValue($literalNode);
            if ($literal === null && !$isNullLiteral) {
                continue;
            }

            $value = $this->satisfyingValue($node::class, $literal, $expected, $node->left === $propertyNode);
            if ($value !== null || $isNullLiteral) {
                $this->requirements[$this->propertyName($propertyNode)] = $value;
            }
            return;
        }
    }

    /** 根据比较运算符和期望真假，给一个能通过的属性值。 */
    private function satisfyingValue(string $operator, mixed $literal, bool $expected, bool $propertyOnLeft): mixed
    {
        $invert = [
            Expr\BinaryOp\Identical::class => Expr\BinaryOp\NotIdentical::class,
            Expr\BinaryOp\NotIdentical::class => Expr\BinaryOp\Identical::class,
            Expr\BinaryOp\Equal::class => Expr\BinaryOp\NotEqual::class,
            Expr\BinaryOp\NotEqual::class => Expr\BinaryOp\Equal::class,
            Expr\BinaryOp\Smaller::class => Expr\BinaryOp\GreaterOrEqual::class,
            Expr\BinaryOp\SmallerOrEqual::class => Expr\BinaryOp\Greater::class,
            Expr\BinaryOp\Greater::class => Expr\BinaryOp\SmallerOrEqual::class,
            Expr\BinaryOp\GreaterOrEqual::class => Expr\BinaryOp\Smaller::class,
        ];
        $flip = [
            Expr\BinaryOp\Smaller::class => Expr\BinaryOp\Greater::class,
            Expr\BinaryOp\SmallerOrEqual::class => Expr\BinaryOp\GreaterOrEqual::class,
            Expr\BinaryOp\Greater::class => Expr\BinaryOp\Smaller::class,
            Expr\BinaryOp\GreaterOrEqual::class => Expr\BinaryOp\SmallerOrEqual::class,
        ];

        if (!$propertyOnLeft) {
            $operator = $flip[$operator] ?? $operator;
        }
        if (!$expected) {
            $operator = $invert[$operator] ?? $operator;
        }

        return match ($operator) {
            Expr\BinaryOp\Identical::class, Expr\BinaryOp\Equal::class => $literal,
            Expr\BinaryOp\NotIdentical::class, Expr\BinaryOp\NotEqual::class => $this->differentValue($literal),
            Expr\BinaryOp\Smaller::class => is_int($literal) ? $literal - 1 : (is_float($literal) ? $literal - 1.0 : 'a'),
            Expr\BinaryOp\SmallerOrEqual::class => $literal,
            Expr\BinaryOp\Greater::class => is_int($literal) ? $literal + 1 : (is_float($literal) ? $literal + 1.0 : 'z'),
            Expr\BinaryOp\GreaterOrEqual::class => $literal,
            default => null,
        };
    }

    private function applyTypeGuard(string $function, array $args, bool $expected): void
    {
        $arg = $args[0] ?? null;
        if (!$arg instanceof Node\Arg || !$this->isThisProperty($arg->value)) {
            return;
        }
        $property = $this->propertyName($arg->value);

        $value = match ($function) {
            'isset' => $expected ? '' : null,
            'empty' => $expected ? '' : 'x',
            'is_array' => $expected ? [] : '',
            'is_string' => $expected ? '' : [],
            'is_int', 'is_integer', 'is_long' => $expected ? 0 : '',
            'is_float', 'is_double' => $expected ? 0.0 : '',
            'is_bool' => $expected ? false : '',
            'is_null' => $expected ? null : '',
            'is_numeric' => $expected ? 0 : '',
            'is_iterable' => $expected ? [] : '',
            default => null,
        };

        $isKnown = in_array($function, [
            'isset', 'empty', 'is_array', 'is_string', 'is_int', 'is_integer', 'is_long',
            'is_float', 'is_double', 'is_bool', 'is_null', 'is_numeric', 'is_iterable',
        ], true);

        if ($isKnown) {
            $this->requirements[$property] = $value;
        }
    }

    private function differentValue(mixed $literal): mixed
    {
        if (is_bool($literal)) {
            return !$literal;
        }
        if (is_int($literal)) {
            return $literal + 1;
        }
        if (is_float($literal)) {
            return $literal + 1.0;
        }
        if (is_string($literal)) {
            return $literal . '_x';
        }
        return 'x';
    }

    private function literalValue(Node $node): mixed
    {
        if ($node instanceof Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        return null;
    }

    private function literalNodeIsNull(Node $node): bool
    {
        return $node instanceof Expr\ConstFetch
            && strtolower($node->name->toString()) === 'null';
    }

    /** 这个分支里是否有值得为它写前置条件的动作。 */
    private function branchMatters(array $stmts): bool
    {
        return $this->finder->findFirst($stmts, static function (Node $node): bool {
            return $node instanceof Expr\FuncCall
                || $node instanceof Expr\MethodCall
                || $node instanceof Expr\StaticCall
                || $node instanceof Expr\New_
                || $node instanceof Expr\Eval_
                || $node instanceof Expr\Include_
                || $node instanceof Stmt\Echo_
                || $node instanceof Expr\Print_
                || $node instanceof Expr\Exit_
                || $node instanceof Expr\Cast\String_
                || ($node instanceof Expr\PropertyFetch
                    && $node->var instanceof Expr\Variable
                    && $node->var->name === 'this');
        }) !== null;
    }

    /** @param array<Node> $stmts */
    private function alwaysTerminates(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Throw_
                || $stmt instanceof Stmt\Break_ || $stmt instanceof Stmt\Continue_) {
                return true;
            }
        }
        return false;
    }

    private function isThisProperty(Node $node): bool
    {
        return $node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier;
    }

    private function propertyName(Node $node): string
    {
        return $node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier
            ? $node->name->toString()
            : '?';
    }
}
