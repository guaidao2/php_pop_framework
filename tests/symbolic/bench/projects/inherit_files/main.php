<?php

declare(strict_types=1);

require __DIR__ . '/src/Base.php';

class Child extends Base
{
}

$child = new Child();
$child->out($_GET['x']);
