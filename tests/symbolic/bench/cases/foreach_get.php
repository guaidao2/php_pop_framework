<?php
// EXPECT: XSS
foreach ($_GET as $value) {
    echo $value;
}
