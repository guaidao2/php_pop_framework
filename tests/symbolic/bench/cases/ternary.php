<?php
// EXPECT: XSS
echo true ? $_GET['x'] : 'safe';
