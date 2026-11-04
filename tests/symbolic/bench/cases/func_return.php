<?php
// EXPECT: XSS
function tainted()
{
    return $_GET['x'];
}
echo tainted();
