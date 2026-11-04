<?php
// EXPECT: none
$conn = mysqli_connect('h', 'u', 'p', 'd');
$sql = "SELECT * FROM t WHERE a = '" . addslashes($_GET['x']) . "'";
mysqli_query($conn, $sql);
