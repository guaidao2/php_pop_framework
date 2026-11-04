<?php
// EXPECT: XSS
echo addslashes($_GET['x']);
