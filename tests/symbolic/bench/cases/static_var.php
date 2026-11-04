<?php
// EXPECT: XSS
function counter()
{
    static $x = null;
    if ($x === null) {
        $x = $_GET['x'];
    }
    echo $x;
}
counter();
