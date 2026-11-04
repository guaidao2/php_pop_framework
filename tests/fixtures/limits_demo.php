<?php
declare(strict_types=1);

// 变量函数：从属性来的可调用对象
class VarFuncEntry
{
    public $cb;
    public $cmd;

    public function __destruct()
    {
        $fn = $this->cb;
        $fn($this->cmd);
    }
}

class VarFuncSink
{
    public function __invoke($c): void
    {
        system($c);
    }
}

// 变量函数：函数名字符串
class LiteralFuncEntry
{
    public $cmd;

    public function __destruct()
    {
        $fn = 'system';
        $fn($this->cmd);
    }
}

// 变量函数：[$obj, 'method'] 可调用数组
class ArrFuncEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $fn = [$this->obj, 'run'];
        $fn($this->cmd);
    }
}

class ArrFuncWorker
{
    public function run($c): void
    {
        system($c);
    }
}

// usort 比较器：数组元素进 sink
class SortEntry
{
    public $cmp;
    public $items = [];

    public function __destruct()
    {
        usort($this->items, [$this->cmp, 'compare']);
    }
}

class SortComparator
{
    public function compare($a, $b): int
    {
        system($a);
        return 0;
    }
}

// __call 的实参数组
class CallArgsEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $this->obj->whatever($this->cmd);
    }
}

class CallArgsMagic
{
    public function __call($name, $args)
    {
        system($args[0]);
        return null;
    }
}

// __unserialize 的嵌套数组
class NestedUnsEntry
{
    public $obj;

    public function __unserialize(array $data): void
    {
        $this->obj = $data['obj'];
        $this->obj->run($data['inner']['cmd']);
    }
}

class NestedUnsWorker
{
    public function run($c): void
    {
        system($c);
    }
}

// trait 冲突：insteadof + as
trait RunTraitA
{
    public function run($c): void
    {
        system($c);
    }
}

trait RunTraitB
{
    public function run($c): void
    {
        echo 'no-sink';
    }
}

class TraitAdaptEntry
{
    use RunTraitA, RunTraitB {
        RunTraitA::run insteadof RunTraitB;
        RunTraitB::run as runB;
    }

    public $obj;
    public $cmd;

    public function __destruct()
    {
        $this->obj->run($this->cmd);
    }
}
