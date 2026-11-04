<?php
// EXPECT: XSS
class A
{
    public function run($v)
    {
        echo $v;
    }
}
$a = new A();
$a->run($_GET['x']);
