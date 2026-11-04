<?php
// EXPECT: XSS
[$a, $b] = [$_GET['x'], 'safe'];
echo $a;
