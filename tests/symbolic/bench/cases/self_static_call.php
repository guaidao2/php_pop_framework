<?php
// EXPECT: XSS
class A
{
    public static function out($v)
    {
        echo $v;
    }

    public static function run($v)
    {
        self::out($v);
    }
}
A::run($_GET['x']);
