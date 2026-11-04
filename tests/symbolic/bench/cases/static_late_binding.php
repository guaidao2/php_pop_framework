<?php
// EXPECT: XSS
class Base
{
    public static function out($v)
    {
    }

    public static function run($v)
    {
        static::out($v);
    }
}
class Child extends Base
{
    public static function out($v)
    {
        echo $v;
    }
}
Child::run($_GET['x']);
