<?php
// pathinfo 数组的 extension 不含路径分隔符
$info = pathinfo($_GET['n'] ?? '');
file_put_contents('/var/www/uploads/file.' . $info['extension'], 'x');
