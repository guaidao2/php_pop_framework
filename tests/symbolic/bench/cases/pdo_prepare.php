<?php
// EXPECT: none
$pdo = new PDO('sqlite::memory:');
$stmt = $pdo->prepare('SELECT * FROM t WHERE a = ?');
$stmt->execute([$_GET['x']]);
