<?php
// 反序列化对象属性进入路径拼接，WAF 在 iconv 之前，可用脏字节绕过
class Settings
{
    public string $encoding = 'UTF-8';
    public string $basePath = '/var/www/html/uploads/';
}

$settings = null;
if (isset($_COOKIE['settings'])) {
    $settings = @unserialize($_COOKIE['settings']);
}
if (!$settings instanceof Settings) {
    $settings = new Settings();
}

$file = (string) ($_GET['f'] ?? '');
if ($file === '') {
    exit;
}

$rawPath = $settings->basePath . $file;
if (preg_match('/flag|\.\.|php:/i', $rawPath)) {
    exit;
}

$converted = @iconv($settings->encoding, 'UTF-8//IGNORE', $rawPath);
if ($converted === false) {
    exit;
}

$content = @file_get_contents($converted);
