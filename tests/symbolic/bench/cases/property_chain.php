<?php
// EXPECT: XSS
class Inner
{
    public $value;
}
class Outer
{
    public $inner;
}
$outer = new Outer();
$outer->inner = new Inner();
$outer->inner->value = $_GET['x'];
echo $outer->inner->value;
