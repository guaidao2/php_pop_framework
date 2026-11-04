<?php

declare(strict_types=1);

require __DIR__ . '/src/Loggable.php';

class Logger
{
    use Loggable;
}

$logger = new Logger();
$logger->log($_GET['x']);
