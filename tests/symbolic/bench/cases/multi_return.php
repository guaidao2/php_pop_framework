<?php
// EXPECT: XSS
function f($c)
{
    if ($c) {
        return $_GET['x'];
    }

    return 'safe';
}
echo f(true);
