<?php
// EXPECT: PATH_TRAVERSAL
copy($_GET['src'], $_GET['dst']);
