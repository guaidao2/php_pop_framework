<?php
// EXPECT: XSS
echo filter_var($_GET['x'], FILTER_VALIDATE_EMAIL);
