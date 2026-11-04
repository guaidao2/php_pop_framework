<?php
// EXPECT: XSS
echo $_SERVER['HTTP_HOST'];
