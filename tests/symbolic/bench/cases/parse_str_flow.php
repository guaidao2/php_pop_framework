<?php
// EXPECT: XSS
parse_str($_GET['q'], $out);
echo $out['x'];
