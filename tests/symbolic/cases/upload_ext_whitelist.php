<?php
// 扩展名白名单 + MIME 白名单：不报任意文件上传
if (!empty($_FILES['f']['name'])) {
    $types = ['image/jpeg', 'image/png'];
    $ext = strtolower(pathinfo($_FILES['f']['name'], PATHINFO_EXTENSION));
    if (in_array($_FILES['f']['type'], $types) && in_array($ext, ['jpg', 'png'], true)) {
        move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . uniqid() . '.' . $ext);
    }
}
