<?php

declare(strict_types=1);

require __DIR__ . '/src/Input.php';
require __DIR__ . '/src/View.php';
require __DIR__ . '/src/Repository/UserRepository.php';
require __DIR__ . '/src/Controller/UserController.php';

$controller = new \App\Controller\UserController();
$controller->index();
