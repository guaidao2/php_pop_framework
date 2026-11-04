<?php

declare(strict_types=1);

namespace App;

class View
{
    public static function render(string $template, array $data): void
    {
        extract($data);
        include __DIR__ . '/templates/' . $template . '.php';
    }
}
