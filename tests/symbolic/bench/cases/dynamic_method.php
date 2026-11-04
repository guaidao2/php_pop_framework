<?php
// EXPECT: XSS
class A
{
    public function out($v)
    {
        echo $v;
    }
}
$m = 'out';
$a = new A();
$a->$m($_GET['x']);
