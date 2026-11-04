<?php
// EXPECT: XSS
$obj = new class {
    public function out($v)
    {
        echo $v;
    }
};
$obj->out($_GET['x']);
