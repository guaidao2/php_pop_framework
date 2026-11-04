<?php
// EXPECT: SSRF
$ch = curl_init($_GET['u']);
