<?php
// EXPECT: none
$x = $_GET['x'];
if ($x === 'abc' && preg_match('/^z/', $x)) {
    echo $x;
}
