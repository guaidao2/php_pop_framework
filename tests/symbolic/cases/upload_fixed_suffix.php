<?php
// 目标扩展名固定为 .jpg，不可控
$name = basename($_FILES['f']['name'] ?? '');
move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/uploads/' . $name . '.jpg');
