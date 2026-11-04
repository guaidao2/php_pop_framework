<?php
// 只给被读路径补分隔符，根目录侧没补，/uploads-evil 仍可命中前缀
$raw = '/var/www/uploads/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath('/var/www/uploads');
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal) !== 0) {
    exit;
}
readfile($raw);
