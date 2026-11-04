<?php
// EXPECT: XSS
$a = ['x' => 'safe'];
foreach ($a as &$v) {
    $v = $_GET['x'];
}
echo $a['x'];
