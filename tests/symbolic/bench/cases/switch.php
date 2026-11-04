<?php
// EXPECT: XSS
$m = $_GET['m'];
switch ($m) {
    case 'a':
        echo $m;
        break;
    default:
        echo 'x';
}
