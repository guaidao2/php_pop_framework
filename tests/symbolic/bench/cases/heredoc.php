<?php
// EXPECT: XSS
$x = $_GET['x'];
echo <<<HTML
<div>$x</div>
HTML;
