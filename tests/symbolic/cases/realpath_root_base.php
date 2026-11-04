<?php
// realpath('/') 的包含性校验没有限制作用，仍可读 /flag
$file = (string) ($_GET['f'] ?? '');
$raw = $file;

$real = realpath($raw);
$baseReal = realpath('/');
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}

$content = @file_get_contents($raw);
