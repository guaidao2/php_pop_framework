<?php
// EXPECT: RCE
preg_replace_callback('/x/', 'system', $_GET['c']);
