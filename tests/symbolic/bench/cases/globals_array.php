<?php
// EXPECT: XSS
$GLOBALS['x'] = $_GET['x'];
echo $GLOBALS['x'];
