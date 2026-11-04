<?php
// 只校验客户端可控的 MIME，没有扩展名黑名单：任意文件上传
if (!empty($_FILES['f']['name'])) {
    $ext = pathinfo($_FILES['f']['name'], PATHINFO_EXTENSION);
    $newName = uniqid() . '.' . $ext;
    $types = ['image/jpeg', 'image/png', 'application/pdf'];
    if (in_array($_FILES['f']['type'], $types)) {
        move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . $newName);
    }
}
