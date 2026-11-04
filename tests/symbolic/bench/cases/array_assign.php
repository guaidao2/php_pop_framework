<?php
// EXPECT: XSS
$a = [];
$a['k'] = $_GET['x'];
echo $a['k'];
