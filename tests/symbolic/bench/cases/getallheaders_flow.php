<?php
// EXPECT: XSS
$headers = getallheaders();
echo $headers['X-Test'];
