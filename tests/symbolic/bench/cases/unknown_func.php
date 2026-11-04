<?php
// EXPECT: XSS
$out = unknown_filter($_GET['x']);
echo $out;
