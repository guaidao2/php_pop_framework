<?php
// EXPECT: none
$x = $_GET['a'];
$x = htmlspecialchars($x);
echo $x;
