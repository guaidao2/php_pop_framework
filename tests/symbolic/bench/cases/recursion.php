<?php
// EXPECT: XSS
function recurse($depth)
{
    if ($depth <= 0) {
        echo $_GET['x'];

        return;
    }
    recurse($depth - 1);
}
recurse(3);
