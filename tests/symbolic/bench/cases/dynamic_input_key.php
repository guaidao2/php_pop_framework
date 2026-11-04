<?php
// EXPECT: XSS
$key = $_GET['k'];
echo $_GET[$key];
