<?php
// base 来自用户输入，包含性校验没有意义，不能当作净化
$base = (string) ($_GET['base'] ?? '/var/www/uploads');
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
readfile($raw);
