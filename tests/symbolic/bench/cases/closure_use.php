<?php
// EXPECT: XSS
$prefix = $_GET['p'];
$f = function ($v) use ($prefix) {
    echo $prefix . $v;
};
$f('x');
