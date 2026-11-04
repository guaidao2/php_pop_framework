<?php
// EXPECT: XSS
$k = $_GET['k'];
$a = [];
$a[$k] = $_GET['v'];
echo $a[$k];
