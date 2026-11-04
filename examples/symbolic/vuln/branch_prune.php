<?php

declare(strict_types=1);

// 演示路径约束剪枝：
//   1. $key 不可能同时等于 admin 和 guest，system() 分支不可达
//   2. 净化后的输出不应报告 XSS
$key = $_GET['key'];

if ($key === 'admin' && $key === 'guest') {
    system($key);
}

if ($key === 'safe') {
    echo htmlspecialchars($key);
}
