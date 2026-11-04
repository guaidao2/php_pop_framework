<?php
declare(strict_types=1);

// 典型三步链：入口 → 方法调用边 → sink 读取方法参数
class ChainEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $this->obj->run($this->cmd);
    }
}

class CmdRunner
{
    public function run(string $c): void
    {
        system($c);
    }
}
