<?php
// EXPECT: XSS
echo json_encode($_GET['x']);
