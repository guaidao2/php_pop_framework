<?php
// EXPECT: XSS
echo escapeshellarg($_GET['c']);
