<?php
// 只有弱前缀检查（没有补分隔符），不能当作包含性校验
$base = '/var/www/html/uploads';
$file = (string) ($_GET['f'] ?? '');
$raw = $base . '/' . $file;

$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real, $baseReal) !== 0) {
    exit;
}

$content = @file_get_contents($raw);
