<?php
// 拆成两个 if 的等价包含性校验
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false) {
    exit;
}
if (strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
$content = @file_get_contents($raw);
