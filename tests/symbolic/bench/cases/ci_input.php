<?php
// EXPECT: XSS
class Controller
{
    private $input;

    public function run()
    {
        echo $this->input->get('name');
    }
}
(new Controller())->run();
