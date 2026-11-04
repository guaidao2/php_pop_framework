<?php

declare(strict_types=1);

function render_value(string $value): void
{
    echo '<p>' . $value . '</p>';
}

render_value($_GET['v']);
