<?php

declare(strict_types=1);

namespace App\Controller;

use App\Input;
use App\Repository\UserRepository;
use App\View;

class UserController
{
    public function index(): void
    {
        $name = Input::get('name');
        (new UserRepository())->find($name);
        View::render('user', ['name' => $name]);
    }
}
