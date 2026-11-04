<?php
declare(strict_types=1);

// 各类文件 sink，用于验证 --read-file / --write-file 等选项与默认值
class ReadEntry
{
    public $obj;

    public function __destruct()
    {
        file_get_contents($this->obj);
    }
}

class WriteEntry
{
    public $obj;
    public $data;

    public function __destruct()
    {
        file_put_contents($this->obj, $this->data);
    }
}

class DeleteEntry
{
    public $obj;

    public function __destruct()
    {
        unlink($this->obj);
    }
}

class MoveEntry
{
    public $obj;

    public function __destruct()
    {
        rename($this->obj, '/tmp/popchain_dst.txt');
    }
}

class IncludeEntry
{
    public $obj;

    public function __destruct()
    {
        include $this->obj;
    }
}
