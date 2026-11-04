<?php

declare(strict_types=1);

// 反射型 XSS：输入未经净化直接输出
$name = $_GET['name'];

if (strlen($name) > 0) {
    echo '<h1>Hello, ' . $name . '</h1>';
}
