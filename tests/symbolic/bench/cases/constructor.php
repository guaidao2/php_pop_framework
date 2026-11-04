<?php
// EXPECT: XSS
class A
{
    public function __construct($v)
    {
        echo $v;
    }
}
new A($_GET['x']);
