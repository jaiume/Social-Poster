<?php

declare(strict_types=1);

namespace App\DAO;

use PDO;

abstract class BaseDao
{
    public function __construct(protected readonly PDO $db)
    {
    }
}
