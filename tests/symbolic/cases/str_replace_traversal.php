<?php
// 删除路径分隔符后，文件名不能再穿越
$name = str_replace(['/', '\'], '', (string) ($_FILES['file']['name'] ?? ''));
$dst = '/var/www/uploads/' . $name;
move_uploaded_file($_FILES['file']['tmp_name'] ?? '', $dst);
