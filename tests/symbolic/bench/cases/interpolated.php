<?php
// EXPECT: XSS
$name = $_GET['name'];
echo "Hello $name";
