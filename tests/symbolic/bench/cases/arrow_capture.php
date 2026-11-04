<?php
// EXPECT: XSS
$prefix = $_GET['p'];
$f = fn($v) => $prefix . $v;
echo $f('x');
