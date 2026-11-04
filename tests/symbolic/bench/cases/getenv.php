<?php
// EXPECT: XSS
echo getenv('PATH');
