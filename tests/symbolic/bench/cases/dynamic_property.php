<?php
// EXPECT: XSS
class A
{
    public $x;
}
$a = new A();
$a->x = $_GET['v'];
$prop = 'x';
echo $a->$prop;
