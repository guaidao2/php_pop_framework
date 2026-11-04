<?php
// EXPECT: XSS
printf('%s', $_GET['x']);
