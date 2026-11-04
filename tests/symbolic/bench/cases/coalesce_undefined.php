<?php
// EXPECT: XSS
echo $undefined ?? $_GET['x'];
