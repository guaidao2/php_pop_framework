<?php
// EXPECT: RCE
$dir = $_GET['d'];
`ls $dir`;
