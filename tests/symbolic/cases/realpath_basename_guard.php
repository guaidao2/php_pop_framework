<?php
// 守卫 realpath 的是 basename 后的路径，实际读的却是原始 $f，不能算收口
$base = '/var/www/uploads';
$f = (string) ($_GET['f'] ?? '');
$checked = $base . '/' . basename($f);
$real = realpath($checked);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
readfile($f);
