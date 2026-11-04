<?php
// EXPECT: XSS
class A
{
    public static $value;

    public static function set($v)
    {
        self::$value = $v;
    }

    public static function get()
    {
        return self::$value;
    }
}
A::set($_GET['x']);
echo A::get();
