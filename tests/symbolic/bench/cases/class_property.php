<?php
// EXPECT: XSS
class Store
{
    public $value;

    public function set($v)
    {
        $this->value = $v;
    }

    public function show()
    {
        echo $this->value;
    }
}
$s = new Store();
$s->set($_GET['x']);
$s->show();
