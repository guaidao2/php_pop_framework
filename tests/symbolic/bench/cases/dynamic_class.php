<?php
// EXPECT: XSS
class A
{
    public function out($v)
    {
        echo $v;
    }
}
$class = 'A';
$object = new $class();
$object->out($_GET['x']);
