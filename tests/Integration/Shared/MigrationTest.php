<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The migration builds the schema from an empty SQLite file and its down() removes it. Runs
 * bin/console in a child process against a temporary file, away from DAMA's static connection.
 */
final class MigrationTest extends TestCase
{
    private const TABLES = [
        'attendance', 'audit_event', 'check_in_window', 'course', 'credit_grant',
        'doctrine_migration_versions', 'enrollment', 'lesson_session', 'password_reset_request', 'student', 'teacher',
    ];

    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir().'/tanzhaken-migration-'.bin2hex(random_bytes(6)).'.db';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($this->databaseFile.$suffix)) {
                unlink($this->databaseFile.$suffix);
            }
        }
    }

    public function test_migrate_up_and_down_on_an_empty_database(): void
    {
        $this->console('doctrine:migrations:migrate', '-n');
        self::assertSame(self::TABLES, $this->tableNames());

        $this->console('doctrine:schema:validate');

        $this->console('doctrine:migrations:migrate', 'first', '-n');
        self::assertSame(['doctrine_migration_versions'], $this->tableNames());
    }

    private function console(string ...$arguments): void
    {
        $process = new Process(
            [\PHP_BINARY, 'bin/console', ...$arguments],
            \dirname(__DIR__, 3),
            ['APP_ENV' => 'test', 'DATABASE_URL' => 'sqlite:///'.$this->databaseFile],
            timeout: 120,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), implode(' ', $arguments)."\n".$process->getOutput().$process->getErrorOutput());
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databaseFile]);
        try {
            $names = array_map(
                static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
                $connection->createSchemaManager()->introspectTableNames(),
            );
        } finally {
            $connection->close();
        }
        sort($names);

        return $names;
    }
}
