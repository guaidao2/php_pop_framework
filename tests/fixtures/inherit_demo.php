<?php
declare(strict_types=1);

// 继承：抽象父类里定义入口与 sink，子类只负责可实例化
abstract class BaseRunner
{
    protected $cmd;

    public function __destruct()
    {
        self::dispatch($this->cmd);
    }

    private static function dispatch($command): void
    {
        system($command);
    }
}

class ChildRunner extends BaseRunner
{
    public $extra = null;
}

class GrandChildRunner extends ChildRunner
{
    public $tag = null;
}

// 同一个对象内的 $this->m() 跳转，不应该产生 payload 伪属性
class SelfCallRunner
{
    public $cmd;

    public function __destruct()
    {
        $this->run();
    }

    private function run(): void
    {
        system($this->cmd);
    }
}
