<?php

declare(strict_types=1);

require __DIR__ . '/src/Helper.php';

use App\Helper;

Helper::render($_GET['x']);
