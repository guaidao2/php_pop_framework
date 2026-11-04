<?php
// EXPECT: none
$x = $_GET['a'];
extract(['x' => 'safe']);
echo $x;
