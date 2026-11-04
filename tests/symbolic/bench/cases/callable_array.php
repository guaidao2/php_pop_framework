<?php
// EXPECT: RCE
class Runner
{
    public function run($cmd)
    {
        system($cmd);
    }
}
$r = new Runner();
call_user_func([$r, 'run'], $_GET['c']);
