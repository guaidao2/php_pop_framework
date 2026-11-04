<?php
// EXPECT: XSS
class Base
{
    public function out($v)
    {
        echo $v;
    }
}
class Child extends Base
{
}
$c = new Child();
$c->out($_GET['x']);
