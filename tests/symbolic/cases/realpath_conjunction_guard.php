<?php
// 合取守卫：$enabled 为 false 时不退出，路径不受包含性限制
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
$enabled = (bool) ($_GET['on'] ?? false);
if (($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) && $enabled) {
    exit;
}
readfile($raw);
