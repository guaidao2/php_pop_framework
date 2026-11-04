<?php
// EXPECT: none
echo filter_var($_GET['x'], FILTER_SANITIZE_SPECIAL_CHARS);
