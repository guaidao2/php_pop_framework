<?php

declare(strict_types=1);

// 本地文件包含
$page = $_GET['page'];

if ($page !== 'home') {
    include $page;
}
