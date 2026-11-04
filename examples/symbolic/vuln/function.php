<?php

declare(strict_types=1);

// 跨函数污点传播：参数进入函数后到达 sink
function render(string $value): void
{
    echo '<div>' . $value . '</div>';
}

render($_GET['q']);
