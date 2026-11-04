<?php

declare(strict_types=1);

trait Loggable
{
    public function log($value): void
    {
        echo $value;
    }
}
