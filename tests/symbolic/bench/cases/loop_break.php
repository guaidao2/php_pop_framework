<?php
// EXPECT: none
foreach ($_GET as $value) {
    break;
    echo $value;
}
