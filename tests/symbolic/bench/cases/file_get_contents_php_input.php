<?php
// EXPECT: XSS
echo file_get_contents('php://input');
