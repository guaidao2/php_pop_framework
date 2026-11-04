<?php
// 无关的 'php' 字面量不能抑制真实的上传问题
$format = $_GET['format'] ?? '';
if ($format === 'php') {
    echo 'debug';
}
if (!empty($_FILES['f']['name'])) {
    $types = ['image/jpeg', 'image/png'];
    $ext = pathinfo($_FILES['f']['name'], PATHINFO_EXTENSION);
    if (in_array($_FILES['f']['type'], $types)) {
        move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . uniqid() . '.' . $ext);
    }
}
