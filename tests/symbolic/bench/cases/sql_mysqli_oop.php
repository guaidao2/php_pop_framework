<?php
// EXPECT: SQLI
$mysqli = new mysqli('localhost', 'u', 'p', 'd');
$mysqli->query('SELECT * FROM t WHERE a = ' . $_GET['x']);
