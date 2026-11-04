<?php
// EXPECT: none
switch ($_GET['m']) {
    case 'a':
        echo 'a';
        break;
    default:
        echo 'default';
}
