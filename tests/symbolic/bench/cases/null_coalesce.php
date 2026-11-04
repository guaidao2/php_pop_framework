<?php
// EXPECT: XSS
$v = $_GET['x'] ?? 'default';
echo $v;
