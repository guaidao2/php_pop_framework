<?php
// EXPECT: none
for ($i = 0; $i < 2; $i++) {
    for ($j = 0; $j < 2; $j++) {
        break 2;
        echo $_GET['x'];
    }
}
