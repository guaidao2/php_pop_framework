<?php

class GadgetOnlyInput
{
    protected $file = '/tmp/pwned';

    public function __destruct()
    {
        @unlink($this->file);
    }

    public function handle()
    {
        $raw = file_get_contents('php://input');
        unserialize($raw);
    }
}
