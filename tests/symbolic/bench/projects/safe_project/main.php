<?php

declare(strict_types=1);

$name = htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
echo $name;

$id = intval($_GET['id']);
$conn = mysqli_connect('localhost', 'u', 'p', 'd');
mysqli_query($conn, 'SELECT * FROM t WHERE id = ' . $id);
