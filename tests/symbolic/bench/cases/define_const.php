<?php
// EXPECT: XSS
define('X', $_GET['x']);
echo X;
