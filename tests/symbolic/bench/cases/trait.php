<?php
// EXPECT: XSS
trait T
{
    public function out($v)
    {
        echo $v;
    }
}
class A
{
    use T;
}
$a = new A();
$a->out($_GET['x']);
