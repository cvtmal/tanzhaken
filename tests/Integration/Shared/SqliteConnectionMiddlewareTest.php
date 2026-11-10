<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SqliteConnectionMiddlewareTest extends KernelTestCase
{
    public function test_sqlite_pragmas_are_set_on_connect(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
    }

    public function test_php_runs_in_utc(): void
    {
        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());
    }
}
