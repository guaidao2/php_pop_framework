<?php
// EXPECT: none
$pdo = new PDO('sqlite::memory:');
$stmt = $pdo->prepare('SELECT * FROM t WHERE a = ?');
$stmt->bindValue(1, $_GET['x']);
$stmt->execute();
