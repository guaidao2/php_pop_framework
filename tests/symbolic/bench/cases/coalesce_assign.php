<?php
// EXPECT: XSS
$x = $_GET['a'];
$x ??= 'default';
echo $x;
