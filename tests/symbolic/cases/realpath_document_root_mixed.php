<?php
// base 混入攻击者变量时，DOCUMENT_ROOT 不能自证清白
$base = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . ($_GET['u'] ?? '');
$raw = $base . '/' . ($_GET['f'] ?? '');
$real = realpath($raw);
$baseReal = realpath($base);
if ($real === false || $baseReal === false || strpos($real . '/', $baseReal . '/') !== 0) {
    exit;
}
readfile($raw);
