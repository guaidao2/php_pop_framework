<?php
// MIME 白名单 + 扩展名白名单/黑名单：不报任意文件上传
if (!empty($_FILES['f']['name'])) {
    $ext = strtolower(pathinfo($_FILES['f']['name'], PATHINFO_EXTENSION));
    $types = ['image/jpeg', 'image/png'];
    $safe = in_array($_FILES['f']['type'], $types, true)
        && in_array($ext, ['jpg', 'png'], true)
        && !in_array($ext, ['php', 'phtml', 'phar'], true);
    if ($safe) {
        move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . uniqid() . '.' . $ext);
    }
}
