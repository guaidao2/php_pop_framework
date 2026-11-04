<?php
// realpath + 前缀校验把读取限制在 uploads 下
$base = '/var/www/html/uploads/';
$file = (string) ($_GET['f'] ?? '');
$raw = $base . $file;

$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}

$content = @file_get_contents($raw);
