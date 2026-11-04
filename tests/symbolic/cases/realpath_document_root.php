<?php
// DOCUMENT_ROOT 是服务端配置，不能当作攻击者可控 base
$dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads';
$raw = $dir . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($dir);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
$content = @file_get_contents($raw);
