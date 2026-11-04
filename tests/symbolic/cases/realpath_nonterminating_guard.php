<?php
// 正向守卫分支不终止，sink 在守卫之后仍可达
$raw = '/var/www/uploads/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath('/var/www/uploads');
$log = [];
if ($real !== false && $baseReal !== false && strpos($real . '/', $baseReal . '/') === 0) {
    $log[] = $real;
}
readfile($raw);
