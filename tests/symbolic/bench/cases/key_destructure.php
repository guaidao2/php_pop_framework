<?php
// EXPECT: XSS
['x' => $a] = ['x' => $_GET['x']];
echo $a;
