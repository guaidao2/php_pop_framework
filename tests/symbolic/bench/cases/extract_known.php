<?php
// EXPECT: XSS
extract(['x' => $_GET['x']]);
echo $x;
