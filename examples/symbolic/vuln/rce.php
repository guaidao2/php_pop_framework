<?php

declare(strict_types=1);

// OS 命令注入
$cmd = $_POST['cmd'];

if (strlen($cmd) > 0) {
    system($cmd);
}
