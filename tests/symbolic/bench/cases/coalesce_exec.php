<?php
// EXPECT: RCE
$v = null ?? system($_GET['c']);
