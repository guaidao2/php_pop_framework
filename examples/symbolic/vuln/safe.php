<?php

declare(strict_types=1);

// 安全写法：净化/类型转换后不应产生漏洞
$name = $_GET['name'];
echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

$id = intval($_GET['id']);
$conn = mysqli_connect('127.0.0.1', 'app', 'app', 'test');
$sql = 'SELECT * FROM users WHERE id = ' . $id;
mysqli_query($conn, $sql);

$page = basename($_GET['page']);
include __DIR__ . '/pages/' . $page . '.php';
