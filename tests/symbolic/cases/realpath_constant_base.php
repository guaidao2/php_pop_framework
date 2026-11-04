<?php
// 根目录是常量表达式（__DIR__ . '/uploads'），属于有效包含性校验
$dir = __DIR__ . '/uploads';
$raw = $dir . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($dir);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
$content = @file_get_contents($raw);
