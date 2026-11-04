<?php
declare(strict_types=1);

// 同构链，但 Cacher 没有 __wakeup，filename 保持可控
class WakeOpenApp
{
    protected $output;

    public function __destruct()
    {
        echo $this->output;
    }
}

class WakeOpenView
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

class WakeOpenCacher
{
    private $filename = '/tmp/cloudnote.cache';
    public $ttl = 3600;

    public function __get($name)
    {
        if ($name !== 'content') {
            return null;
        }
        return @file_get_contents($this->filename);
    }
}
