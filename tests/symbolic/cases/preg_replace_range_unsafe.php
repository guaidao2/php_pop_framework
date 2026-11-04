<?php
// 区间字符类隐式包含 / 和 \，不能当作分隔符净化
$name = preg_replace('/[^ -~]/', '', (string) ($_GET['n'] ?? ''));
file_put_contents('/var/www/uploads/' . $name, 'x');
