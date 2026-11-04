<?php
// EXPECT: XSS
function out()
{
    $args = func_get_args();
    echo $args[0];
}
out($_GET['x']);
