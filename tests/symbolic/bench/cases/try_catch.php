<?php
// EXPECT: XSS
try {
    echo $_GET['x'];
} catch (Exception $e) {
    echo 'err';
}
