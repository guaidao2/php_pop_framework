<?php
// Yoda 写法：0 !== strpos(...)
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || 0 !== strpos($real . '/', $baseReal . '/')) {
    exit;
}
readfile($raw);
