<?php
// EXPECT: XSS
$input = $_GET['x'];
function out()
{
    global $input;
    echo $input;
}
out();
