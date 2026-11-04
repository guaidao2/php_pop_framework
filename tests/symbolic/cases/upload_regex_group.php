<?php
// 分组正则黑名单：扩展名已检查
$name = $_FILES['f']['name'] ?? '';
if (preg_match('/\.(php|phtml)$/i', $name)) {
    exit;
}
move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . $name);
