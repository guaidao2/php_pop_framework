<?php
// 白名单字符类替换会把路径分隔符替换掉
$name = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($_GET['n'] ?? ''));
file_put_contents('/var/www/uploads/' . $name, 'x');
