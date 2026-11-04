<?php
// 三元分支里只有默认值是安全后缀，另一分支仍可控
$isAvatar = (bool) ($_GET['avatar'] ?? false);
$dst = '/uploads/' . ($isAvatar ? basename($_FILES['f']['name'] ?? '') : 'default.jpg');
move_uploaded_file($_FILES['f']['tmp_name'], $dst);
