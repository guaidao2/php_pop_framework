<?php
// EXPECT: XSS
function out($v)
{
    echo $v;
}
out($_GET['x']);
