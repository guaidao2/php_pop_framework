<?php
// pathinfo 的 extension 不含路径分隔符
$ext = pathinfo($_GET['n'] ?? '', PATHINFO_EXTENSION);
file_put_contents('/var/www/uploads/file.' . $ext, 'x');
