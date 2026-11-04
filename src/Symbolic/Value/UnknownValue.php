<?php

declare(strict_types=1);

namespace PopChain\Symbolic\Value;

use PopChain\Symbolic\Expr\Expr;
use PopChain\Symbolic\Expr\VarExpr;
use PopChain\Symbolic\Symbolic\Taint;
use PopChain\Symbolic\Symbolic\Type;

/**
 * 完全未知的值。
 *
 * 仍然保存一个稳定的变量表达式，使得同一路径上的
 * "未知值"在约束中可被识别为同一个符号。
 */
final class UnknownValue extends Value
{
    private VarExpr $expr;

    public function __construct(string $name = '<?>', ?Taint $taint = null)
    {
        parent::__construct($taint);
        $this->expr = new VarExpr($name, Type::Mixed);
    }

    public function type(): Type
    {
        return Type::Mixed;
    }

    public function describe(): string
    {
        return '<?>';
    }

    public function toExpr(): Expr
    {
        return $this->expr;
    }

    public function merge(Value $other): Value
    {
        return new self(
            ValueFactory::freshName('merged'),
            $this->taint->union($other->taint),
        );
    }
}
