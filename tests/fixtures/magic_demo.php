<?php
declare(strict_types=1);

// 读取未知属性触发 __get
class GetEntry
{
    public $obj;

    public function __destruct()
    {
        echo $this->obj->anything;
    }
}

// isset() 触发 __isset
class IssetEntry
{
    public $obj;

    public function __destruct()
    {
        if (isset($this->obj->flag)) {
            echo 'hit';
        }
    }
}

class MagicBag
{
    public $cmd;

    public function __get($name)
    {
        system($this->cmd);
        return null;
    }
}

class IssetBag
{
    public $cmd;

    public function __isset($name)
    {
        system($this->cmd);
        return true;
    }
}
