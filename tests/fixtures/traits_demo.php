<?php
declare(strict_types=1);

// trait 成员合并：__destruct 与属性都写在 trait 里，使用类自己是空的
trait CommandTrait
{
    public $cmd;
    private $suffix = '';

    public function __destruct()
    {
        system($this->cmd);
    }
}

class TraitEntry
{
    use CommandTrait;
}

trait NestedTrait
{
    use CommandTrait;

    public function ping(): void
    {
        echo 'pong';
    }
}

class NestedTraitEntry
{
    use NestedTrait;
}

// trait 里定义普通方法，作为调用目标时应该落到使用它的具体类
trait RunTrait
{
    public function run($c): void
    {
        system($c);
    }
}

class UsesRunTrait
{
    use RunTrait;
}

class TraitTargetEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $this->obj->run($this->cmd);
    }
}
