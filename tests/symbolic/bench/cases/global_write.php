<?php
// EXPECT: XSS
$input = 'safe';
function set_input()
{
    global $input;
    $input = $_GET['x'];
}
set_input();
echo $input;
