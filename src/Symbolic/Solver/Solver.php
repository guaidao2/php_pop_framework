<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Solver;

use PopChain\Symbolic\Expr\BinaryExpr;
use PopChain\Symbolic\Expr\CallExpr;
use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\Simplifier;
use PopChain\Symbolic\Expr\UnaryExpr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Symbolic\Unknown;

/**
 * 内置路径约束求解器。
 *
 * 这是一个面向 Web 安全审计的轻量级求解器，不做完整的 SMT 求解，
 * 而是使用"区间 + 常量绑定 + 字符串谓词"的抽象：
 *
 *   - 整数/长度约束：维护区间，区间为空即 UNSAT
 *   - 相等约束：常量绑定，绑定冲突即 UNSAT
 *   - 字符串约束：contains / starts_with / ends_with / preg_match
 *   - 类型谓词：is_int / is_string / is_array ...
 *   - 布尔结构：AND 展开，OR 分支尝试，NOT 取反
 *
 * 无法判定时返回 Unknown（保守，不剪枝），从而保证不会因为
 * 求解器不精确而漏掉可达的漏洞路径。
 */
final class Solver
{
    /** @var array<string, array{value: mixed, strict: bool}> */
    private array $bindings = [];

    /** @var array<string, list<array{value: mixed, strict: bool}>> */
    private array $neq = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $intRanges = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $lenRanges = [];

    /** @var array<string, list<int>> */
    private array $neqLengths = [];

    /** @var array<string, list<string>> */
    private array $contains = [];

    /** @var array<string, list<string>> */
    private array $notContains = [];

    /** @var array<string, string> */
    private array $startsWith = [];

    /** @var array<string, string> */
    private array $endsWith = [];

    /** @var array<string, list<string>> */
    private array $patterns = [];

    /** @var array<string, list<string>> */
    private array $notPatterns = [];

    /** @var array<string, array<string, true>> */
    private array $types = [];

    /** @var array<string, array<string, true>> */
    private array $notTypes = [];

    /** @var array<string, list<mixed>> */
    private array $inValues = [];

    /** @var array<string, list<mixed>> */
    private array $notInValues = [];

    /** @var list<Expr> */
    private array $unknown = [];

    private bool $unsat = false;

    private int $checks = 0;

    private int $pruned = 0;

    private int $unknownCount = 0;

    /**
     * @param list<Expr> $constraints
     */
    public function check(array $constraints): Status
    {
        $this->checks++;
        $this->resetFacts();

        $filtered = [];
        foreach ($constraints as $constraint) {
            $constraint = Simplifier::simplify($constraint);
            if ($constraint->isConstant()) {
                if (!$constraint->constantValue()) {
                    $this->pruned++;

                    return Status::Unsat;
                }
                continue;
            }
            $filtered[] = $constraint;
        }

        if ($filtered === []) {
            return Status::Sat;
        }

        // 先把 AND 展平为原子列表，再展开 OR，才能处理 (a || b) && c 这类结构。
        $filtered = $this->flattenAnd($filtered);
        $candidates = $this->expandOr($filtered, 64);
        if ($candidates === null) {
            $this->unknownCount++;

            return Status::Unknown;
        }

        $sawUnknown = false;
        foreach ($candidates as $atoms) {
            $this->resetFacts();
            $status = $this->checkConjunction($atoms);
            if ($status === Status::Sat) {
                return Status::Sat;
            }
            if ($status === Status::Unknown) {
                $sawUnknown = true;
            }
        }

        if ($sawUnknown) {
            $this->unknownCount++;

            return Status::Unknown;
        }

        $this->pruned++;

        return Status::Unsat;
    }

    /**
     * @return array{checks: int, pruned: int, unknown: int}
     */
    public function stats(): array
    {
        return [
            'checks' => $this->checks,
            'pruned' => $this->pruned,
            'unknown' => $this->unknownCount,
        ];
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    private function resetFacts(): void
    {
        $this->bindings = [];
        $this->neq = [];
        $this->intRanges = [];
        $this->lenRanges = [];
        $this->neqLengths = [];
        $this->contains = [];
        $this->notContains = [];
        $this->startsWith = [];
        $this->endsWith = [];
        $this->patterns = [];
        $this->notPatterns = [];
        $this->types = [];
        $this->notTypes = [];
        $this->inValues = [];
        $this->notInValues = [];
        $this->unknown = [];
        $this->unsat = false;
    }

    /**
     * 把嵌套的 AND 展平为原子列表。
     *
     * @param list<Expr> $exprs
     * @return list<Expr>
     */
    private function flattenAnd(array $exprs): array
    {
        $atoms = [];
        foreach ($exprs as $expr) {
            if ($expr instanceof BinaryExpr && ($expr->op === '&&' || $expr->op === 'and')) {
                $atoms = array_merge($atoms, $this->flattenAnd([$expr->left, $expr->right]));
            } else {
                $atoms[] = $expr;
            }
        }

        return $atoms;
    }

    /**
     * 把 OR 展开为若干合取候选。超过上限时返回 null（整体判 UNKNOWN）。
     *
     * @param list<Expr> $atoms
     * @return list<list<Expr>>|null
     */
    private function expandOr(array $atoms, int $limit): ?array
    {
        $candidates = [[]];
        foreach ($atoms as $atom) {
            $branches = $this->orBranches($atom);
            if (count($branches) === 1 && $branches[0] === [$atom]) {
                foreach ($candidates as &$candidate) {
                    $candidate[] = $atom;
                }
                unset($candidate);
                continue;
            }

            $next = [];
            foreach ($candidates as $prefix) {
                foreach ($branches as $branch) {
                    if (count($next) >= $limit) {
                        return null;
                    }
                    $next[] = array_merge($prefix, $branch);
                }
            }
            $candidates = $next;
        }

        return $candidates;
    }

    /**
     * @return list<list<Expr>>
     */
    private function orBranches(Expr $expr): array
    {
        if ($expr instanceof BinaryExpr && ($expr->op === '||' || $expr->op === 'or')) {
            return array_merge($this->orBranches($expr->left), $this->orBranches($expr->right));
        }
        if ($expr instanceof UnaryExpr && $expr->op === '!') {
            $inner = $this->negate($expr->operand);
            if ($inner->canonical() !== $expr->canonical()) {
                return $this->orBranches($inner);
            }
        }

        return [[$expr]];
    }

    /**
     * @param list<Expr> $atoms
     */
    private function checkConjunction(array $atoms): Status
    {
        foreach ($atoms as $atom) {
            $this->applyAtom(Simplifier::simplify($atom));
            if ($this->unsat) {
                return Status::Unsat;
            }
        }

        return $this->finish();
    }

    private function applyAtom(Expr $expr): void
    {
        $expr = Simplifier::simplify($expr);

        if ($expr->isConstant()) {
            if (!$expr->constantValue()) {
                $this->unsat = true;
            }

            return;
        }

        if ($expr instanceof UnaryExpr && $expr->op === '!') {
            if ($expr->operand instanceof CallExpr) {
                $this->applyCall($expr->operand, true);

                return;
            }
            $inner = $this->negate($expr->operand);
            if ($inner->canonical() === $expr->canonical()) {
                $this->unknown[] = $expr;

                return;
            }
            $this->applyAtom($inner);

            return;
        }

        if ($expr instanceof BinaryExpr) {
            switch ($expr->op) {
                case '&&':
                case 'and':
                    $this->applyAtom($expr->left);
                    $this->applyAtom($expr->right);

                    return;
                case '==':
                case '===':
                case '!=':
                case '!==':
                case '<':
                case '<=':
                case '>':
                case '>=':
                    $this->compare($expr->left, $expr->op, $expr->right);

                    return;
                case '||':
                case 'or':
                    // 未展开的 OR（例如候选数超限）无法精确处理
                    $this->unknown[] = $expr;

                    return;
            }
        }

        if ($expr instanceof CallExpr) {
            $this->applyCall($expr, false);

            return;
        }

        $this->unknown[] = $expr;
    }

    /**
     * 对布尔表达式取反（用于处理 !(...)）。
     */
    private function negate(Expr $expr): Expr
    {
        if ($expr instanceof UnaryExpr && $expr->op === '!') {
            return $expr->operand;
        }

        if ($expr instanceof BinaryExpr) {
            // De Morgan
            if ($expr->op === '&&' || $expr->op === 'and') {
                return Simplifier::binary('||', $this->negate($expr->left), $this->negate($expr->right));
            }
            if ($expr->op === '||' || $expr->op === 'or') {
                return Simplifier::binary('&&', $this->negate($expr->left), $this->negate($expr->right));
            }

            $opposite = match ($expr->op) {
                '==' => '!=',
                '!=' => '==',
                '===' => '!==',
                '!==' => '===',
                '<' => '>=',
                '>=' => '<',
                '>' => '<=',
                '<=' => '>',
                default => null,
            };
            if ($opposite !== null) {
                return Simplifier::binary($opposite, $expr->left, $expr->right);
            }
        }

        return Simplifier::unary('!', $expr);
    }

    private function compare(Expr $left, string $op, Expr $right): void
    {
        // 常量统一放右侧
        if ($left->isConstant() && !$right->isConstant()) {
            [$left, $right] = [$right, $left];
            $op = self::flip($op);
        }

        if ($left->isConstant() && $right->isConstant()) {
            $value = (new BinaryExpr($op, $left, $right))->evaluate([]);
            if ($value === true) {
                return;
            }
            if ($value === false) {
                $this->unsat = true;

                return;
            }
            $this->unknown[] = new BinaryExpr($op, $left, $right);

            return;
        }

        // strlen(x) op 常量
        $lengthVar = $this->matchLengthCall($left);
        if ($lengthVar !== null && $right->isConstant() && is_int($right->constantValue())) {
            $this->applyLengthConstraint($lengthVar, $op, $right->constantValue());

            return;
        }

        if ($left instanceof VarExpr && $right->isConstant()) {
            $this->applyVarConstant($left, $op, $right->constantValue());

            return;
        }

        if ($left instanceof VarExpr && $right instanceof VarExpr) {
            if ($left->equals($right)) {
                $result = match ($op) {
                    '==', '===', '<=', '>=' => true,
                    '!=', '!==', '<', '>' => false,
                    default => null,
                };
                if ($result === false) {
                    $this->unsat = true;
                } elseif ($result === null) {
                    $this->unknown[] = new BinaryExpr($op, $left, $right);
                }

                return;
            }

            // 两边都有常量绑定时可判定
            $leftBinding = $this->bindings[$left->name] ?? null;
            $rightBinding = $this->bindings[$right->name] ?? null;
            if ($leftBinding !== null && $rightBinding !== null && $leftBinding['strict'] && $rightBinding['strict']) {
                $value = match ($op) {
                    '==' => $leftBinding['value'] == $rightBinding['value'],
                    '===' => $leftBinding['value'] === $rightBinding['value'],
                    '!=' => $leftBinding['value'] != $rightBinding['value'],
                    '!==' => $leftBinding['value'] !== $rightBinding['value'],
                    '<' => $leftBinding['value'] < $rightBinding['value'],
                    '<=' => $leftBinding['value'] <= $rightBinding['value'],
                    '>' => $leftBinding['value'] > $rightBinding['value'],
                    '>=' => $leftBinding['value'] >= $rightBinding['value'],
                    default => null,
                };
                if ($value === false) {
                    $this->unsat = true;
                } elseif ($value === null) {
                    $this->unknown[] = new BinaryExpr($op, $left, $right);
                }

                return;
            }

            $this->unknown[] = new BinaryExpr($op, $left, $right);

            return;
        }

        $this->unknown[] = new BinaryExpr($op, $left, $right);
    }

    private static function flip(string $op): string
    {
        return match ($op) {
            '<' => '>',
            '<=' => '>=',
            '>' => '<',
            '>=' => '<=',
            default => $op,
        };
    }

    private function matchLengthCall(Expr $expr): ?string
    {
        if ($expr instanceof CallExpr && strtolower($expr->name) === 'strlen' && count($expr->args) === 1) {
            $arg = $expr->args[0];
            if ($arg instanceof VarExpr) {
                return $arg->name;
            }
        }

        return null;
    }

    private function applyVarConstant(VarExpr $var, string $op, mixed $value): void
    {
        $name = $var->name;

        if ($op === '==' || $op === '===') {
            $strict = $op === '===';
            if (!$strict && is_string($value) && is_numeric($value)) {
                // $x == '1' 无法确定 $x 是字符串还是整数，避免过度剪枝
                return;
            }
            if (!$strict && is_float($value)) {
                return;
            }
            $this->recordBinding($name, $value, $strict);

            return;
        }

        if ($op === '!=' || $op === '!==') {
            $this->recordNeq($name, $value, $op === '!==');

            return;
        }

        if ($op === '<=>') {
            $this->unknown[] = new BinaryExpr($op, $var, new \PopChain\Symbolic\Expr\ConstExpr($value));

            return;
        }

        // 整数区间
        if (is_int($value)) {
            if ($var->type()->isString()) {
                $this->unknown[] = new BinaryExpr($op, $var, new \PopChain\Symbolic\Expr\ConstExpr($value));

                return;
            }
            [$lo, $hi] = $this->intRanges[$name] ?? [PHP_INT_MIN, PHP_INT_MAX];
            [$lo, $hi] = self::applyBound($lo, $hi, $op, $value);
            if ($lo > $hi) {
                $this->unsat = true;

                return;
            }
            $this->intRanges[$name] = [$lo, $hi];

            return;
        }

        $this->unknown[] = new BinaryExpr($op, $var, new \PopChain\Symbolic\Expr\ConstExpr($value));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function applyBound(int $lo, int $hi, string $op, int $value): array
    {
        return match ($op) {
            '==' => [max($lo, $value), min($hi, $value)],
            '===' => [max($lo, $value), min($hi, $value)],
            '<' => [$lo, min($hi, $value - 1)],
            '<=' => [$lo, min($hi, $value)],
            '>' => [max($lo, $value + 1), $hi],
            '>=' => [max($lo, $value), $hi],
            default => [$lo, $hi],
        };
    }

    private function applyLengthConstraint(string $var, string $op, int $value): void
    {
        if ($value < 0) {
            if (in_array($op, ['==', '===', '<', '<='], true)) {
                $this->unsat = true;
            }

            return;
        }

        if ($op === '!=' || $op === '!==') {
            $this->neqLengths[$var][] = $value;

            return;
        }

        if ($op === '<=>') {
            return;
        }

        [$lo, $hi] = $this->lenRanges[$var] ?? [0, PHP_INT_MAX];
        [$lo, $hi] = self::applyBound($lo, $hi, $op, $value);
        if ($lo > $hi) {
            $this->unsat = true;

            return;
        }
        $this->lenRanges[$var] = [$lo, $hi];
    }

    private function recordBinding(string $var, mixed $value, bool $strict): void
    {
        $existing = $this->bindings[$var] ?? null;
        if ($existing !== null) {
            $conflict = ($existing['strict'] && $strict)
                ? $existing['value'] !== $value
                : $existing['value'] != $value;
            if ($conflict) {
                $this->unsat = true;

                return;
            }
            if ($strict && !$existing['strict']) {
                $this->bindings[$var] = ['value' => $value, 'strict' => true];
            }

            return;
        }

        $this->bindings[$var] = ['value' => $value, 'strict' => $strict];
    }

    private function recordNeq(string $var, mixed $value, bool $strict): void
    {
        $this->neq[$var][] = ['value' => $value, 'strict' => $strict];
    }

    private function applyCall(CallExpr $call, bool $negated): void
    {
        $name = strtolower($call->name);
        $args = $call->args;

        $constant = $call->evaluate([]);
        if ($constant !== Unknown::value()) {
            $truth = (bool) $constant;
            if ($negated) {
                $truth = !$truth;
            }
            if (!$truth) {
                $this->unsat = true;
            }

            return;
        }

        switch ($name) {
            case 'str_contains':
            case 'str_starts_with':
            case 'str_ends_with':
                $subject = $args[0] ?? null;
                $needle = $args[1] ?? null;
                if ($subject instanceof VarExpr && $needle?->isConstant() && is_string($needle->constantValue())) {
                    $literal = $needle->constantValue();
                    if ($negated) {
                        if ($name === 'str_contains') {
                            $this->notContains[$subject->name][] = $literal;
                        } else {
                            $this->unknown[] = Simplifier::unary('!', $call);
                        }
                    } else {
                        match ($name) {
                            'str_contains' => $this->contains[$subject->name][] = $literal,
                            'str_starts_with' => $this->startsWith[$subject->name] = $literal,
                            'str_ends_with' => $this->endsWith[$subject->name] = $literal,
                        };
                    }

                    return;
                }
                break;

            case 'in_array':
                $needle = $args[0] ?? null;
                $haystack = $args[1] ?? null;
                if ($needle instanceof VarExpr && $haystack?->isConstant() && is_array($haystack->constantValue())) {
                    $values = array_values($haystack->constantValue());
                    if ($negated) {
                        $this->notInValues[$needle->name] = array_merge($this->notInValues[$needle->name] ?? [], $values);
                    } else {
                        $this->inValues[$needle->name] = array_merge($this->inValues[$needle->name] ?? [], $values);
                    }

                    return;
                }
                break;

            case 'preg_match':
                $pattern = $args[0] ?? null;
                $subject = $args[1] ?? null;
                if ($pattern?->isConstant() && is_string($pattern->constantValue()) && $subject instanceof VarExpr) {
                    if ($negated) {
                        $this->notPatterns[$subject->name][] = $pattern->constantValue();
                    } else {
                        $this->patterns[$subject->name][] = $pattern->constantValue();
                    }

                    return;
                }
                break;

            case 'is_int':
            case 'is_string':
            case 'is_array':
            case 'is_bool':
            case 'is_null':
            case 'is_float':
                if (count($args) === 1 && $args[0] instanceof VarExpr) {
                    $this->recordType($args[0]->name, substr($name, 3), !$negated);

                    return;
                }
                break;

            case 'is_numeric':
                if (count($args) === 1 && $args[0] instanceof VarExpr) {
                    $this->recordType($args[0]->name, 'numeric', !$negated);

                    return;
                }
                break;

            case 'ctype_digit':
                if (count($args) === 1 && $args[0] instanceof VarExpr && !$negated) {
                    $this->recordType($args[0]->name, 'numeric-string', true);

                    return;
                }
                break;
        }

        $this->unknown[] = $negated ? Simplifier::unary('!', $call) : $call;
    }

    private function recordType(string $var, string $type, bool $positive): void
    {
        if ($positive) {
            $this->types[$var][$type] = true;
            // int/string/array/bool/null/float 互斥
            $mutuallyExclusive = ['int', 'string', 'array', 'bool', 'null', 'float'];
            if (in_array($type, $mutuallyExclusive, true)) {
                foreach (array_keys($this->types[$var]) as $existing) {
                    if ($existing !== $type && in_array($existing, $mutuallyExclusive, true)) {
                        $this->unsat = true;

                        return;
                    }
                }
            }
        } else {
            $this->notTypes[$var][$type] = true;
        }
    }

    private function finish(): Status
    {
        if ($this->unsat) {
            return Status::Unsat;
        }

        foreach ($this->intRanges as [$lo, $hi]) {
            if ($lo > $hi) {
                return Status::Unsat;
            }
        }
        foreach ($this->lenRanges as [$lo, $hi]) {
            if ($lo > $hi) {
                return Status::Unsat;
            }
        }

        foreach ($this->bindings as $name => $binding) {
            $value = $binding['value'];

            foreach ($this->neq[$name] ?? [] as $neq) {
                $conflict = $binding['strict']
                    ? ($value == $neq['value'])
                    : ($neq['strict'] && is_scalar($value) && $value === $neq['value']);
                if ($conflict) {
                    return Status::Unsat;
                }
            }

            if (!$binding['strict']) {
                continue;
            }

            if (isset($this->lenRanges[$name]) && (is_string($value) || is_int($value))) {
                $length = strlen((string) $value);
                [$lo, $hi] = $this->lenRanges[$name];
                if ($length < $lo || $length > $hi) {
                    return Status::Unsat;
                }
            }
            foreach ($this->neqLengths[$name] ?? [] as $excluded) {
                if ((is_string($value) || is_int($value)) && strlen((string) $value) === $excluded) {
                    return Status::Unsat;
                }
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $string = (string) $value;
                foreach ($this->contains[$name] ?? [] as $needle) {
                    if (!str_contains($string, $needle)) {
                        return Status::Unsat;
                    }
                }
                foreach ($this->notContains[$name] ?? [] as $needle) {
                    if (str_contains($string, $needle)) {
                        return Status::Unsat;
                    }
                }
                if (isset($this->startsWith[$name]) && !str_starts_with($string, $this->startsWith[$name])) {
                    return Status::Unsat;
                }
                if (isset($this->endsWith[$name]) && !str_ends_with($string, $this->endsWith[$name])) {
                    return Status::Unsat;
                }
                foreach ($this->patterns[$name] ?? [] as $pattern) {
                    $matched = @preg_match($pattern, $string);
                    if ($matched === 0) {
                        return Status::Unsat;
                    }
                }
                foreach ($this->notPatterns[$name] ?? [] as $pattern) {
                    $matched = @preg_match($pattern, $string);
                    if ($matched === 1) {
                        return Status::Unsat;
                    }
                }
            }

            if (isset($this->intRanges[$name]) && is_int($value)) {
                [$lo, $hi] = $this->intRanges[$name];
                if ($value < $lo || $value > $hi) {
                    return Status::Unsat;
                }
            }

            if (isset($this->inValues[$name]) && !in_array($value, $this->inValues[$name], true)) {
                return Status::Unsat;
            }
            if (isset($this->notInValues[$name]) && in_array($value, $this->notInValues[$name], true)) {
                return Status::Unsat;
            }

            foreach ($this->types[$name] ?? [] as $type => $_) {
                if (!self::matchesType($value, $type)) {
                    return Status::Unsat;
                }
            }
            foreach ($this->notTypes[$name] ?? [] as $type => $_) {
                if (self::matchesType($value, $type)) {
                    return Status::Unsat;
                }
            }
        }

        // 没有绑定值时的值域冲突
        foreach ($this->inValues as $name => $values) {
            $allowed = $values;
            foreach ($this->neq[$name] ?? [] as $neq) {
                $allowed = array_values(array_filter(
                    $allowed,
                    static fn (mixed $candidate): bool => $candidate != $neq['value'],
                ));
            }
            foreach ($this->notInValues[$name] ?? [] as $excluded) {
                $allowed = array_values(array_filter(
                    $allowed,
                    static fn (mixed $candidate): bool => $candidate !== $excluded,
                ));
            }
            if ($allowed === []) {
                return Status::Unsat;
            }
        }

        foreach ($this->lenRanges as $name => [$lo, $hi]) {
            foreach ($this->neqLengths[$name] ?? [] as $excluded) {
                if ($lo === $hi && $lo === $excluded) {
                    return Status::Unsat;
                }
            }
        }

        return $this->unknown === [] ? Status::Sat : Status::Unknown;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'bool' => is_bool($value),
            'null' => $value === null,
            'float' => is_float($value),
            'numeric' => is_numeric($value),
            'numeric-string' => is_string($value) && ctype_digit($value),
            default => true,
        };
    }
}
