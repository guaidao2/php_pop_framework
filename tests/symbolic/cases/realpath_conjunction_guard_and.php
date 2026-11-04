<?php
// 用 and 关键字的合取守卫：$enabled 为 false 时守卫不生效
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
$enabled = (bool) ($_GET['on'] ?? false);
if (($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) and $enabled) {
    exit;
}
readfile($raw);
