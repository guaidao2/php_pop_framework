<?php
// EXPECT: none
$x = $_GET['x'];
if ($x === 'abc' && str_contains($x, 'z')) {
    echo $x;
}
