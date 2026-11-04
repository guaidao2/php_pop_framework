<?php
// PHP 的 header() 会拒绝含 CRLF 的值，不构成头注入
header('X-Test: ' . ($_GET['v'] ?? ''));
