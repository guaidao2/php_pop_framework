<?php
// EXPECT: XSS
$a = [];
$a['x']['y'] = $_GET['v'];
echo $a['x']['y'];
