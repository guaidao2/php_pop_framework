<?php
// 扩展名判断写成 '.php' 后缀比较
$name = $_FILES['f']['name'] ?? '';
if (str_ends_with($name, '.php')) {
    exit;
}
move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . $name);
