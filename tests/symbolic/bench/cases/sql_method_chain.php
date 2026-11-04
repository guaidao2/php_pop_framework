<?php
// EXPECT: SQLI
$pdo = new PDO('sqlite::memory:');
$pdo->query('SELECT * FROM t WHERE a = ' . $_GET['x'])->fetchAll();
