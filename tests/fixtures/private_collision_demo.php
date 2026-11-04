<?php
declare(strict_types=1);

namespace Verify\Private\Cases;

// 上一个 gadget 与下一个 gadget 声明了同名 private 属性
class EntryP
{
    private $obj;
    private $cmd;

    public function __destruct()
    {
        $this->obj->run($this->cmd);
    }
}

class WorkerP
{
    private $obj = null;
    private $cmd = null;

    public function run($c): void
    {
        system($c);
    }
}
