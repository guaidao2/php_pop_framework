<?php
// EXPECT: XSS
class A
{
    public function run()
    {
        $f = fn() => $this->show($_GET['x']);
        $f();
    }

    private function show($v)
    {
        echo $v;
    }
}
(new A())->run();
