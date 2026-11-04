<?php
// else 分支是包含性校验通过的安全路径
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    http_response_code(403);
} else {
    readfile($raw);
}
