<?php
// EXPECT: XSS
extract($_GET);
echo $name;
