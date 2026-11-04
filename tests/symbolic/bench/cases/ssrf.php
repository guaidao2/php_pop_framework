<?php
// EXPECT: SSRF
file_get_contents($_GET['u']);
