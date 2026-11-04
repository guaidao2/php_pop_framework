<?php

class GadgetOnly
{
    protected $file = '/tmp/pwned';

    public function __destruct()
    {
        @unlink($this->file);
    }
}
