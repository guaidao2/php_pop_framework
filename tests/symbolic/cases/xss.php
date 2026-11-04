<?php

declare(strict_types=1);

$name = $_GET['name'];

if (strlen($name) > 0) {
    echo '<b>' . $name . '</b>';
}
