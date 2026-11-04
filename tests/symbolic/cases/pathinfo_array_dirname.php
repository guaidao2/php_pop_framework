<?php
// pathinfo 数组的 dirname 仍带路径分隔符
$info = pathinfo($_GET['n'] ?? '');
file_put_contents('/var/www/uploads/' . $info['dirname'] . '/a', 'x');
