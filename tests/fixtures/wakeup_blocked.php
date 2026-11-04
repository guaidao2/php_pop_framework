<?php
declare(strict_types=1);

// 深层 gadget：Cacher::__wakeup 会把 filename 重置成常量，最后一步不可控
class WakeBlockedApp
{
    protected $output;

    public function __destruct()
    {
        echo $this->output;
    }
}

class WakeBlockedView
{
    public $template;

    public function __toString(): string
    {
        if (!is_object($this->template)) {
            return 'no template';
        }
        return (string) $this->template->content;
    }
}

class WakeBlockedCacher
{
    private $filename = '/tmp/cloudnote.cache';
    public $ttl = 3600;

    public function __wakeup()
    {
        $this->filename = '/tmp/cloudnote.cache';
    }

    public function __get($name)
    {
        if ($name !== 'content') {
            return null;
        }
        return @file_get_contents($this->filename);
    }
}
