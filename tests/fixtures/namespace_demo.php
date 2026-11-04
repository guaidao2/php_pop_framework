<?php
declare(strict_types=1);

namespace Demo\Ns;

// 命名空间下 private 属性的序列化键需要带上完整类名
class NamespacedEntry
{
    private $cmd;

    public function __destruct()
    {
        system($this->cmd);
    }
}

class Other
{
    public $noop = null;
}
