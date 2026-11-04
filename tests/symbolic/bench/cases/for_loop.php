<?php
// EXPECT: XSS
for ($i = 0; $i < 2; $i++) {
    echo $_GET['x'];
}
