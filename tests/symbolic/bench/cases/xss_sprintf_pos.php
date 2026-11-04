<?php
// EXPECT: XSS
echo sprintf('%1$s', $_GET['x']);
