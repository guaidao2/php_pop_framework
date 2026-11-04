<?php

class GadgetOnlyCommented
{
    protected $file = '/tmp/pwned';

    public function __destruct()
    {
        @unlink($this->file);
    }
}

// legacy: unserialize($_GET['x']);
