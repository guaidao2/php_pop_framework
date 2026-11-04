<?php
// else 分支继续读：取反的 ===0 约束不能当作包含性守卫
$raw = '/var/www/uploads/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath('/var/www/uploads');
if ($real !== false && strpos($real . '/', $baseReal . '/') === 0) {
    echo 'ok';
} else {
    readfile($raw);
}
