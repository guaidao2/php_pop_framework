<?php
// EXPECT: PATH_TRAVERSAL
file_put_contents($_GET['f'], 'x');
