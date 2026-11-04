<?php
declare(strict_types=1);

// 字符串上下文：sprintf / file_get_contents / strval / 插值 / die
class SprintfEntry
{
    public $obj;

    public function __destruct()
    {
        sprintf('[%s]', $this->obj);
    }
}

class ReadEntry
{
    public $obj;

    public function __destruct()
    {
        file_get_contents($this->obj);
    }
}

class StrvalEntry
{
    public $obj;

    public function __destruct()
    {
        $x = strval($this->obj);
    }
}

class InterpEntry
{
    public $obj;

    public function __destruct()
    {
        echo "value: {$this->obj}";
    }
}

class ExitEntry
{
    public $obj;

    public function __destruct()
    {
        die($this->obj);
    }
}

class Render
{
    public $cmd;

    public function __toString(): string
    {
        system($this->cmd);
        return 'rendered';
    }
}

// 局部别名作为方法接收者
class AliasEntry
{
    public $obj;
    public $cmd;

    public function __destruct()
    {
        $runner = $this->obj;
        $runner->run($this->cmd);
    }
}

class AliasWorker
{
    public function run($c): void
    {
        system($c);
    }
}
