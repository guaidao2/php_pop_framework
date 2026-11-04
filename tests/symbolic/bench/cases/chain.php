<?php
// EXPECT: XSS
class View
{
    private $v;

    public function set($value)
    {
        $this->v = $value;

        return $this;
    }

    public function show()
    {
        echo $this->v;
    }
}
(new View())->set($_GET['x'])->show();
