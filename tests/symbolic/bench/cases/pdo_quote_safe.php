<?php
// EXPECT: none
$pdo = new PDO('sqlite::memory:');
$pdo->query('SELECT * FROM t WHERE a = ' . $pdo->quote($_GET['x']));
