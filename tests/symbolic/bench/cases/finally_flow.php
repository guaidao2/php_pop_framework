<?php
// EXPECT: XSS
try {
    echo $_GET['x'];
} finally {
    echo 'done';
}
