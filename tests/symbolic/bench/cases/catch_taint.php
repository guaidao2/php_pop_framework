<?php
// EXPECT: XSS
try {
    throw new Exception($_GET['x']);
} catch (Exception $e) {
    echo $e->getMessage();
}
