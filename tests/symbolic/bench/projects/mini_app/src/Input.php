<?php

declare(strict_types=1);

namespace App;

class Input
{
    public static function get(string $key): string
    {
        return $_GET[$key] ?? '';
    }
}
