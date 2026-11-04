<?php
// EXPECT: none
$x = $_GET['x'];
if (strlen($x) > 5 && strlen($x) < 2) {
    echo $x;
}
