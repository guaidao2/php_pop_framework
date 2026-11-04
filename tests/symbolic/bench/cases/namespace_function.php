<?php
// EXPECT: XSS
namespace App;

function out($v)
{
    echo $v;
}

out($_GET['x']);
