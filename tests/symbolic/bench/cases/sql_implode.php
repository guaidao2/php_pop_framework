<?php
// EXPECT: SQLI
$ids = implode(',', $_GET['ids']);
$conn = mysqli_connect('h', 'u', 'p', 'd');
mysqli_query($conn, 'SELECT * FROM t WHERE id IN (' . $ids . ')');
