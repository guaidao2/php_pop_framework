<?php

declare(strict_types=1);

class Renderer
{
    public function render($value): void
    {
        echo $value;
    }
}

$renderer = new Renderer();
$renderer->render($_POST['html']);
