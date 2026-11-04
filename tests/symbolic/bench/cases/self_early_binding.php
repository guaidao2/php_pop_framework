<?php
// EXPECT: none
class Base
{
    public static function out($v)
    {
        echo htmlspecialchars($v);
    }

    public static function run($v)
    {
        self::out($v);
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
