<?php
// 目标名来自 $_FILES 且没有任何扩展名/MIME 校验：任意文件上传
$name = basename($_FILES['f']['name'] ?? '');
move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . $name);
