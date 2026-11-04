<?php
// 只有用户可控 unserialize，没有可用 gadget
$data = $_GET['payload'] ?? '';
$obj = @unserialize($data);
if ($obj === false) {
    echo 'bad payload';
}
