<?php

declare(strict_types=1);

// SQL 注入：输入被拼接到查询语句
$id = $_GET['id'];

$conn = mysqli_connect('127.0.0.1', 'app', 'app', 'test');
$sql = 'SELECT * FROM users WHERE id = ' . $id;
$result = mysqli_query($conn, $sql);
