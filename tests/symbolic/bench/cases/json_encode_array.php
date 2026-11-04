<?php
// EXPECT: XSS
echo json_encode(['name' => $_GET['x']]);
