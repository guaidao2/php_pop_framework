<?php
declare(strict_types=1);

// 负向场景：报了就是误报

// 1) sink 参数是方法形参，但调用点传的是常量
class NegConstEntry
{
    public $obj;

    public function __destruct()
    {
        $this->obj->run('echo FIXED');
    }
}

class NegConstWorker
{
    public function run($c): void
    {
        system($c);
    }
}

// 2) 目标类自己声明了同名 public 属性，读取不会触发 __get
class NegDeclaredEntry
{
    public $obj;

    public function __destruct()
    {
        echo $this->obj->secret;
    }
}

class NegDeclaredBag
{
    public $secret = 'not-magic';
    public $cmd;

    public function __get($name)
    {
        system($this->cmd);
        return null;
    }
}

// 3) 属性先被赋常量，再进 sink
class NegOverwriteEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $this->cmd = 'echo FIXED';
        $this->obj->run($this->cmd);
    }
}

class NegOverwriteWorker
{
    public function run($c): void
    {
        system($c);
    }
}
