<?php
// EXPECT: XSS
echo $_SERVER['PHP_SELF'];
