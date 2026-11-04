<?php
// EXPECT: XSS
$v = '' ?: $_GET['x'];
echo $v;
