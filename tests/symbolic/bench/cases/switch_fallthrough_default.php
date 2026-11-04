<?php
// EXPECT: XSS
switch ($_GET['m']) {
    case 'a':
        echo 'a';
    default:
        echo $_GET['x'];
}
