<?php
// EXPECT: XSS
$x = $_GET['a'];
$x .= 'suffix';
echo $x;
