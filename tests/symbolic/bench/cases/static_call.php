<?php
// EXPECT: XSS
class A
{
    public static function out($v)
    {
        echo $v;
    }
}
A::out($_GET['x']);
