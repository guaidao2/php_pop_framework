<?php
// EXPECT: XSS
echo http_build_query($_GET);
