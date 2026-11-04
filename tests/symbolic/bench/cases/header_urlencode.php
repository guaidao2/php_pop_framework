<?php
// EXPECT: none
header('X-Test: ' . urlencode($_GET['x']));
