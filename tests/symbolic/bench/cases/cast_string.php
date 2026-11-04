<?php
// EXPECT: XSS
echo (string) $_GET['x'];
