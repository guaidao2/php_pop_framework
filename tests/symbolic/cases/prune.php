<?php

declare(strict_types=1);

$k = $_GET['k'];

// 不可达：同一个变量不可能同时等于两个不同的值
if ($k === 'a' && $k === 'b') {
    system($k);
}

// 净化后输出
if ($k === 'safe') {
    echo htmlspecialchars($k);
}
