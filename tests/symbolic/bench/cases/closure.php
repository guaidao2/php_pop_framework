<?php
// EXPECT: XSS
$f = function ($v) {
    echo $v;
};
$f($_GET['x']);
