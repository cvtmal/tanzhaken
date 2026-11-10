<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Raw DBAL rows for constraint tests: inserts bypass the entities, so the database's own
 * unique keys and foreign keys are what is tested. Use in a KernelTestCase.
 */
trait DbalRows
{
    private static function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Inserts one row; Uuid values as their 16-byte binary, dates as UTC `Y-m-d H:i:s`,
     * backed enums by their value.
     *
     * @param array<string, mixed> $row
     */
    private static function insertRow(string $table, array $row): void
    {
        $values = [];
        foreach ($row as $column => $value) {
            if ($value instanceof Uuid) {
                // Bound as a string, like Doctrine's UuidType does: SQLite stores TEXT, and a
                // BLOB-bound value would never equal the parent's id.
                $values[$column] = $value->toBinary();
            } elseif ($value instanceof \DateTimeImmutable) {
                $values[$column] = $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } elseif ($value instanceof \BackedEnum) {
                $values[$column] = $value->value;
            } else {
                $values[$column] = $value;
            }
        }

        self::connection()->insert($table, $values);
    }

    private static function countRows(string $table): int
    {
        return (int) self::connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    /**
     * The ids of every row in the table, as RFC 4122 strings, sorted.
     *
     * @return list<string>
     */
    private static function rowIds(string $table): array
    {
        $ids = array_map(
            static fn (mixed $id): string => Uuid::fromBinary((string) $id)->toRfc4122(),
            self::connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s', $table)),
        );
        sort($ids);

        return $ids;
    }
}
