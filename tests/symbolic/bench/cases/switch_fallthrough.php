<?php
// EXPECT: XSS
$m = $_GET['m'];
switch ($m) {
    case 'a':
        echo $_GET['x'];
    case 'b':
        echo 'b';
}
