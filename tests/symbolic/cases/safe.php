<?php

declare(strict_types=1);

echo htmlspecialchars($_GET['q']);

$id = intval($_GET['id']);
$conn = mysqli_connect('localhost', 'u', 'p', 'db');
mysqli_query($conn, 'SELECT * FROM t WHERE id = ' . $id);

$file = basename($_GET['f']);
include __DIR__ . '/pages/' . $file . '.php';
