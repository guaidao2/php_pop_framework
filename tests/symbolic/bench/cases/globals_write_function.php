<?php
// EXPECT: XSS
$GLOBALS['x'] = 'safe';
function set_global()
{
    $GLOBALS['x'] = $_GET['x'];
}
set_global();
echo $GLOBALS['x'];
