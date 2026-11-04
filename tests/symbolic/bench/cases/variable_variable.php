<?php
// EXPECT: XSS
$name = $_GET['n'];
echo $$name;
