<?php
// EXPECT: XSS
$x = null;
$x ??= $_GET['a'];
echo $x;
