<?php
// 只删除 .. 不是路径分隔符净化
$name = str_replace('..', '', (string) ($_GET['n'] ?? ''));
file_put_contents('/var/www/uploads/' . $name, 'x');
