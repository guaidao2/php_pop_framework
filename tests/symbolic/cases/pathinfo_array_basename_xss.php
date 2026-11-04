<?php
// pathinfo 数组的 basename 仍带源污点
$info = pathinfo($_GET['x'] ?? '');
echo $info['basename'];
