<?php
// realpath 的结果是本地路径，不能再当 URL 用
$base = '/var/www/uploads/';
$path = realpath($base . ($_GET['f'] ?? ''));
if ($path === false) {
    exit;
}
readfile($path);
