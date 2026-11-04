<?php

declare(strict_types=1);

$conn = mysqli_connect('localhost', 'u', 'p', 'db');
$sql = "SELECT * FROM users WHERE name = '" . $_GET['name'] . "'";
mysqli_query($conn, $sql);
