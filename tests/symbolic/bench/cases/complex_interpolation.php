<?php
// EXPECT: XSS
echo "Hello {$_GET['x']}";
