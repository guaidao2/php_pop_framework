<?php
// 正向事实在 && 合取里，安全分支
$base = '/var/www/uploads';
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real !== false && $baseReal !== false && strpos($real . '/', $baseReal . '/') === 0) {
    readfile($raw);
}
