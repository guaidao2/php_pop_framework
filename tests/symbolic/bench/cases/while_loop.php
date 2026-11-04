<?php
// EXPECT: XSS
$i = 0;
while ($i < 2) {
    echo $_GET['x'];
    $i++;
}
