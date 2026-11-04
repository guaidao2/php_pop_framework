<?php
// EXPECT: XSS
class User
{
    public function __construct(public string $name)
    {
    }

    public function show()
    {
        echo $this->name;
    }
}
$u = new User($_GET['x']);
$u->show();
