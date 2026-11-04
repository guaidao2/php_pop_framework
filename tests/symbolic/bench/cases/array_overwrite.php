<?php
// EXPECT: none
$a = [];
$a['k'] = $_GET['x'];
$a['k'] = 'safe';
echo $a['k'];
