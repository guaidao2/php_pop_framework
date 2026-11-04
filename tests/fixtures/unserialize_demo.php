<?php
declare(strict_types=1);

// __unserialize 的标准写法：sink 参数直接取自 $data 数组
class UnsDirectEntry
{
    public $obj;

    public function __unserialize(array $data): void
    {
        $this->obj = $data['obj'];
        $this->obj->run($data['cmd']);
    }
}

class UnsWorker
{
    public function run($c): void
    {
        system($c);
    }
}

// 属性先赋值、再进 sink
class UnsPropEntry
{
    public $obj;
    public $cmd;

    public function __unserialize(array $data): void
    {
        $this->obj = $data['obj'];
        $this->cmd = $data['cmd'];
        $this->obj->run($this->cmd);
    }
}
