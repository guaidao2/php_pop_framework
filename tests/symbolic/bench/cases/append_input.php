<?php
// EXPECT: XSS
$_GET['a'][] = $_GET['x'];
echo $_GET['a'][0];
