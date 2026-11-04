<?php
// subject 被 str_replace 包装过，前缀检查针对的不是原值
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos(str_replace('..', '', $real) . '/', $baseReal . '/') !== 0) {
    exit;
}
readfile($raw);
