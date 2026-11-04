<?php
// 不在包含范围内反而继续读取，前缀校验方向被用反，不能当作净化
$base = '/var/www/html/uploads';
$file = (string) ($_GET['f'] ?? '');
$raw = $base . '/' . $file;

$real = realpath($raw);
$baseReal = realpath($base);
if ($real !== false && $baseReal !== false && strpos($real . '/', $baseReal . '/') !== 0) {
    $content = @file_get_contents($raw);
    echo $content;
}
