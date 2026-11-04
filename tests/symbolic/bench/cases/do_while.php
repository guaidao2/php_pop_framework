<?php
// EXPECT: XSS
$i = 0;
do {
    echo $_GET['x'];
    $i++;
} while ($i < 2);
