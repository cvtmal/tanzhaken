<?php

declare(strict_types=1);

namespace App\Shared\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Applies the SQLite connection settings (foreign keys, busy timeout, WAL) on
 * every new connection. Any other platform passes through untouched.
 *
 * A higher priority wraps the driver earlier, i.e. closer to the real
 * connection. It must sit inside the test bundle's static-connection
 * middleware (priority 100), which opens a transaction right after connecting:
 * journal_mode cannot change and foreign_keys is a no-op inside a transaction.
 */
#[AsMiddleware(priority: 200)]
final class SqliteConnectionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        if (!$driver->getDatabasePlatform(new StaticServerVersionProvider('3')) instanceof SQLitePlatform) {
            return $driver;
        }

        return new SqliteConnectionDriver($driver);
    }
}
