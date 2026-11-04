<?php
// EXPECT: XSS
echo urldecode($_GET['x']);
