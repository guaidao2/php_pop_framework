<?php
// EXPECT: SQLI
$conn = mysqli_connect('h', 'u', 'p', 'd');
$sql = "SELECT * FROM t WHERE a = '" . htmlspecialchars($_GET['x']) . "'";
mysqli_query($conn, $sql);
