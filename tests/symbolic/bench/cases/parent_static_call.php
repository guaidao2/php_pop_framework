<?php
// EXPECT: XSS
class Base
{
    public static function out($v)
    {
        echo $v;
    }
}
class Child extends Base
{
    public static function run($v)
    {
        parent::out($v);
    }
}
Child::run($_GET['x']);
