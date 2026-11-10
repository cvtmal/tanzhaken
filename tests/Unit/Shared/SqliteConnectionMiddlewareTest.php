<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Doctrine\SqliteConnectionDriver;
use App\Shared\Doctrine\SqliteConnectionMiddleware;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySqlDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use PHPUnit\Framework\TestCase;

final class SqliteConnectionMiddlewareTest extends TestCase
{
    public function test_it_leaves_a_non_sqlite_driver_alone(): void
    {
        $driver = new MySqlDriver();

        self::assertSame($driver, (new SqliteConnectionMiddleware())->wrap($driver));
    }

    public function test_it_wraps_the_sqlite_driver(): void
    {
        $driver = new SqliteDriver();

        self::assertInstanceOf(SqliteConnectionDriver::class, (new SqliteConnectionMiddleware())->wrap($driver));
    }
}
