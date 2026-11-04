<?php

declare(strict_types=1);

namespace App\Repository;

class UserRepository
{
    public function find(string $name)
    {
        $pdo = new \PDO('sqlite::memory:');

        return $pdo->query("SELECT * FROM users WHERE name = '" . $name . "'");
    }
}
