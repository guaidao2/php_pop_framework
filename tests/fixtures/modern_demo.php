<?php
declare(strict_types=1);

namespace Verify\Modern;

// 同一个方法里既有 first-class callable，又有真实调用
class ModernEntry
{
    public $runner;
    public $cmd;

    public function __destruct()
    {
        $fn = $this->runner->run(...);
        $this->runner->run($this->cmd);
    }
}

class SysRunner
{
    public function run($cmd = 'echo FIXED'): void
    {
        system($cmd);
    }
}
