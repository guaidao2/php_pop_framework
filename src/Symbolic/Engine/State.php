<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Engine;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\Simplifier;
use PopChain\Symbolic\Solver\Solver;
use PopChain\Symbolic\Solver\Status;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Value\ScalarValue;
use PopChain\Symbolic\Value\UnknownValue;
use PopChain\Symbolic\Value\Value;
use PopChain\Symbolic\Value\ValueFactory;

/**
 * 一个符号执行状态。
 *
 * 不可变约定：fork 时复制 env / constraints 数组，Value 本身不可变，
 * 因此不需要深拷贝。globals 用对象共享，用于 global 变量写回。
 */
final class State
{
    /** @var array<string, Value> */
    public array $env;

    /** @var list<Expr> */
    public array $constraints;

    /** @var array<string, true> */
    public array $globalAliases = [];

    public ?Signal $signal = null;

    public bool $unsat = false;

    public bool $truncated = false;

    public int $callDepth = 0;

    public int $loopDepth = 0;

    public string $file = '';

    public ?string $namespace = null;

    /** @var array<string, string> 小写别名 => 完整类名 */
    public array $uses = [];

    /** @var array<string, true> */
    public array $includedFiles = [];

    /** extract() 之后，未定义变量可能来自输入参数 */
    public ?Taint $extractedTaint = null;

    /** 当前方法所属的类（用于 self:: / static:: 解析） */
    public ?string $currentClass = null;

    /** 实际调用类（用于 static:: 延迟绑定） */
    public ?string $currentStaticClass = null;

    public GlobalScope $globals;

    public bool $isGlobalScope = false;

    /**
     * @param array<string, Value> $env
     * @param list<Expr> $constraints
     */
    public function __construct(
        array $env,
        GlobalScope $globals,
        array $constraints = [],
        bool $isGlobalScope = false,
    ) {
        $this->env = $env;
        $this->globals = $globals;
        $this->constraints = $constraints;
        $this->isGlobalScope = $isGlobalScope;
    }

    public function copy(): self
    {
        $copy = clone $this;
        $copy->env = $this->env;
        $copy->constraints = $this->constraints;
        $copy->globalAliases = $this->globalAliases;
        $copy->uses = $this->uses;
        $copy->includedFiles = $this->includedFiles;

        return $copy;
    }

    public function get(string $name): Value
    {
        $name = self::normalize($name);
        if (array_key_exists($name, $this->env)) {
            return $this->env[$name];
        }

        // global 变量在函数内声明后可访问
        if (isset($this->globalAliases[$name]) && array_key_exists($name, $this->globals->vars)) {
            return $this->globals->vars[$name];
        }

        // extract() 过的开放数组：未定义变量可能由攻击者提供
        if ($this->extractedTaint !== null && $this->extractedTaint->isTainted()) {
            return new \PopChain\Symbolic\Value\ScalarValue(
                new \PopChain\Symbolic\Expr\VarExpr($name, \PopChain\Symbolic\Symbolic\Type::Mixed),
                $this->extractedTaint,
            );
        }

        // PHP 中未定义变量读取为 null（并产生 notice）
        return new ScalarValue(new \PopChain\Symbolic\Expr\ConstExpr(null));
    }

    public function has(string $name): bool
    {
        $name = self::normalize($name);

        return array_key_exists($name, $this->env)
            || (isset($this->globalAliases[$name]) && array_key_exists($name, $this->globals->vars));
    }

    public function set(string $name, Value $value): void
    {
        $name = self::normalize($name);
        $this->env[$name] = $value;

        if ($this->isGlobalScope) {
            $this->globals->vars[$name] = $value;
        } elseif (isset($this->globalAliases[$name])) {
            $this->globals->vars[$name] = $value;
        }
    }

    public function remove(string $name): void
    {
        unset($this->env[self::normalize($name)]);
    }

    private static function normalize(string $name): string
    {
        return str_starts_with($name, '$') ? $name : '$' . $name;
    }

    public function addConstraint(Expr $constraint): void
    {
        $constraint = Simplifier::simplify($constraint);
        if ($constraint->isConstant()) {
            if (!$constraint->constantValue()) {
                $this->unsat = true;
            }

            return;
        }

        $this->constraints[] = $constraint;
    }

    public function isFeasible(Solver $solver): bool
    {
        if ($this->unsat) {
            return false;
        }

        return $solver->check($this->constraints) !== Status::Unsat;
    }

    public function terminate(Signal $signal): void
    {
        $this->signal = $signal;
    }

    public function isTerminated(): bool
    {
        return $this->unsat || $this->signal !== null;
    }

    /**
     * 取当前状态的常量布尔值；无法确定时返回 null。
     */
    public function constantTruth(Expr $expr): ?bool
    {
        $expr = Simplifier::simplify($expr);
        if (!$expr->isConstant()) {
            return null;
        }

        return (bool) $expr->constantValue();
    }

    /**
     * 读取常量值；无法确定时返回 Unknown。
     */
    public function constantValue(Expr $expr): mixed
    {
        $expr = Simplifier::simplify($expr);

        return $expr->isConstant() ? $expr->constantValue() : \PopChain\Symbolic\Symbolic\Unknown::value();
    }
}
