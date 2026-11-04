<?php
// EXPECT: XSS
echo sprintf('%s', $_GET['x']);
