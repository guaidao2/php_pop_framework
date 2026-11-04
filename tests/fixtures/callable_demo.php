<?php
declare(strict_types=1);

// 方法回调数组（实参可控）
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

// call_user_func_array：参数是数组，不能按标量映射
class CufaEntry
{
    public $fn;
    public $args;

    public function __destruct()
    {
        call_user_func_array($this->fn, $this->args);
    }
}

class Invoker
{
    public function __invoke($cmd): void
    {
        system($cmd);
    }
}

// array_map：传的是数组元素
class MapEntry
{
    public $cb;
    public $items = [];

    public function __destruct()
    {
        array_map([$this->cb, 'handle'], $this->items);
    }
}

class Handler2
{
    public function handle($item): void
    {
        system($item);
    }
}

// 常量实参：不可控，不应报链
class ConstCallEntry
{
    public $obj;

    public function __destruct()
    {
        $this->obj->run('echo FIXED');
    }
}

class FixedRunner
{
    public function run($c): void
    {
        system($c);
    }
}
