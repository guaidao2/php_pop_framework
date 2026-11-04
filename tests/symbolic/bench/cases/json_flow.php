<?php
// EXPECT: XSS
$data = json_decode($_POST['j'], true);
echo $data['x'];
