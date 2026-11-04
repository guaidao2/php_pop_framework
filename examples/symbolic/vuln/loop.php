<?php

declare(strict_types=1);

// 数组与循环中的污点传播
$fields = ['name' => $_GET['name'], 'city' => $_POST['city']];

foreach ($fields as $key => $value) {
    if ($key === 'name') {
        echo 'Name: ' . $value;
    }
}
