<?php
// EXPECT: none
$conn = mysqli_connect('h', 'u', 'p', 'd');
$sql = sprintf("SELECT * FROM t WHERE a = '%s'", mysqli_real_escape_string($conn, $_GET['x']));
mysqli_query($conn, $sql);
