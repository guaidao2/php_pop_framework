<?php
declare(strict_types=1);

namespace PopChain\Symbolic;

use PhpParser\Node;
use PhpParser\Node\Expr as PhpExpr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PopChain\Engine\EngineInterface;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Solver\Solver;
use PopChain\Symbolic\Solver\Status;

/**
 * 进程内引擎：用 SymPHP 的表达式与求解器检查反序列化链上的守卫条件。
 *
 * 输入是 ChainConstraintSolver 发来的 guard_conditions（表达式 + 期望真假），
 * 把 PHP 表达式翻译成 SymPHP Expr，交给内置 Solver 判 SAT / UNSAT / UNKNOWN。
 * 返回 unsat 时链会被跳过；unknown 时保持启发式结果，避免误杀。
 */
final class SymbolicEngine implements EngineInterface
{
    private ?Parser $parser = null;

    public function name(): string
    {
        return 'symphp';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function analyze(array $request): ?array
    {
        $conditions = [];
        foreach ((array) ($request['guard_conditions'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['expr'])) {
                continue;
            }
            $conditions[] = [
                'expr' => (string) $item['expr'],
                'expected' => (bool) ($item['expected'] ?? true),
                'class' => (string) ($item['class'] ?? ''),
            ];
        }

        if ($conditions === []) {
            return ['status' => 'unknown', 'assignments' => [], 'notes' => ['engine: no guard conditions to check']];
        }

        // 同一表达式同时要求真和假，说明来自 if/else 两种分支，不能当成矛盾
        $grouped = [];
        foreach ($conditions as $condition) {
            $grouped[$condition['expr']][] = $condition;
        }

        $constraints = [];
        $skipped = 0;
        foreach ($grouped as $expr => $items) {
            $expectedValues = array_values(array_unique(array_map(
                static fn (array $item): bool => $item['expected'],
                $items,
            )));
            if (count($expectedValues) > 1) {
                continue;
            }

            $compiled = $this->translate($expr, $items[0]['class']);
            if ($compiled === null) {
                $skipped++;
                continue;
            }

            $constraints[] = $expectedValues[0] ? $compiled : Expr::unary('!', $compiled);
        }

        if ($constraints === []) {
            return [
                'status' => 'unknown',
                'assignments' => [],
                'notes' => ['engine: guard expressions could not be translated'],
            ];
        }

        try {
            $status = (new Solver())->check($constraints);
        } catch (\Throwable $exception) {
            return null;
        }

        $label = match ($status) {
            Status::Unsat => 'unsat',
            Status::Sat => 'sat',
            Status::Unknown => 'unknown',
        };

        return [
            'status' => $label,
            'assignments' => [],
            'notes' => [sprintf(
                'engine: %s (%d constraint%s, %d skipped)',
                $status->label(),
                count($constraints),
                count($constraints) === 1 ? '' : 's',
                $skipped,
            )],
        ];
    }

    private function translate(string $code, string $class): ?Expr
    {
        try {
            $stmts = $this->parser()->parse('<?php ' . $code . ';');
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_array($stmts) || !isset($stmts[0]) || !$stmts[0] instanceof Stmt\Expression) {
            return null;
        }

        return $this->convert($stmts[0]->expr, $class);
    }

    private function convert(Node $node, string $class): ?Expr
    {
        if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            return Expr::literal($node->value);
        }

        if ($node instanceof PhpExpr\ConstFetch) {
            $name = strtolower($node->name->toString());
            return match ($name) {
                'true' => Expr::literal(true),
                'false' => Expr::literal(false),
                'null' => Expr::literal(null),
                default => null,
            };
        }

        if ($node instanceof PhpExpr\PropertyFetch
            && $node->var instanceof PhpExpr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier) {
            return Expr::variable($this->scopedName($class, '$this->' . $node->name->toString()));
        }

        if ($node instanceof PhpExpr\Variable && is_string($node->name)) {
            return Expr::variable($this->scopedName($class, '$' . $node->name));
        }

        if ($node instanceof PhpExpr\BooleanNot) {
            $inner = $this->convert($node->expr, $class);
            return $inner === null ? null : Expr::unary('!', $inner);
        }

        if ($node instanceof PhpExpr\UnaryMinus || $node instanceof PhpExpr\UnaryPlus) {
            $inner = $this->convert($node->expr, $class);
            if ($inner === null) {
                return null;
            }
            return Expr::unary($node instanceof PhpExpr\UnaryMinus ? '-' : '+', $inner);
        }

        if ($node instanceof PhpExpr\BinaryOp) {
            $left = $this->convert($node->left, $class);
            $right = $this->convert($node->right, $class);
            if ($left === null || $right === null) {
                return null;
            }
            return Expr::bin($node->getOperatorSigil(), $left, $right);
        }

        if ($node instanceof PhpExpr\FuncCall && $node->name instanceof Node\Name) {
            $args = [];
            foreach ($node->args as $arg) {
                if (!$arg instanceof Node\Arg) {
                    return null;
                }
                $converted = $this->convert($arg->value, $class);
                if ($converted === null) {
                    return null;
                }
                $args[] = $converted;
            }
            return Expr::call(strtolower($node->name->toString()), $args);
        }

        return null;
    }

    private function scopedName(string $class, string $variable): string
    {
        return $class === '' ? $variable : $class . '::' . $variable;
    }

    private function parser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        }
        return $this->parser;
    }
}
