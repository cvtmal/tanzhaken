<?php

declare(strict_types=1);

namespace App\Shared\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqliteConnectionDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        $connection = parent::connect($params);

        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');

        $inMemory = isset($params['memory']) || ':memory:' === ($params['path'] ?? null);
        if (!$inMemory) {
            // The result is ignored: a file system without WAL support keeps its mode.
            $connection->query('PRAGMA journal_mode = WAL')->free();
            $connection->exec('PRAGMA synchronous = NORMAL');
        }

        return $connection;
    }
}
