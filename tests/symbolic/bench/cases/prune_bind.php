<?php
// EXPECT: none
$x = $_GET['x'];
if ($x === 'a' && $x === 'b') {
    echo $x;
}
