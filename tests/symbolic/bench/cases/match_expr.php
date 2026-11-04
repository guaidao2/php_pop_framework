<?php
// EXPECT: XSS
$v = 'a';
echo match ($v) {
    'a' => $_GET['x'],
    default => 'safe',
};
