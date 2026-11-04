<?php
// 检查的是未解析的 $raw，却用 realpath 结果做包含性判断，不能算守卫
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($raw . '/', $baseReal . '/') !== 0) {
    exit;
}
readfile($raw);
