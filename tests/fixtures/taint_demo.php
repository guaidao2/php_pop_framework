<?php
declare(strict_types=1);

// 局部变量污点：sink 参数先赋给局部变量，再层层传递
class TaintEntry
{
    public $cmd;

    public function __destruct()
    {
        $first = $this->cmd;
        $second = $first;
        system($second);
    }
}

// 字符串拼接：字面量前缀 + 可控属性
class ConcatEntry
{
    public $name;

    public function __destruct()
    {
        system('echo ' . $this->name);
    }
}

// 方法回调数组：[$this->handler, 'run']
class CallableArrayEntry
{
    public $handler;
    public $cmd;

    public function __destruct()
    {
        call_user_func([$this->handler, 'run'], $this->cmd);
    }
}

class Handler
{
    public function run(string $c): void
    {
        system($c);
    }
}

// 属性到属性的赋值
class PropCopyEntry
{
    public $source;
    public $target;

    public function __destruct()
    {
        $this->target = $this->source;
        system($this->target);
    }
}
