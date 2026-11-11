<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * PERF-2 / S8: concurrent scans on SQLite. Each scan runs in its own PHP process (its own
 * kernel and a real connection, away from DAMA's static one) against a temporary migrated
 * SQLite file; all start at the same instant. The unique key must leave exactly one row
 * per student, and different students must never block each other into an error.
 */
#[Group('concurrency')]
final class CheckInConcurrencyTest extends TestCase
{
    private const PROCESSES = 8;
    private const CHILD = 'tests/Integration/Attendance/Fixtures/record_check_in.php';

    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir().'/tanzhaken-checkin-'.bin2hex(random_bytes(6)).'.db';
        // Also warms var/cache/test before the children boot their kernels.
        $this->console('doctrine:migrations:migrate', '-n');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($this->databaseFile.$suffix)) {
                unlink($this->databaseFile.$suffix);
            }
        }
    }

    public function test_eight_concurrent_scans_of_one_student_record_one_row(): void
    {
        [$lessonId, $studentIds] = $this->seed(1);

        $outcomes = $this->scanConcurrently($lessonId, array_fill(0, self::PROCESSES, $studentIds[0]));

        sort($outcomes);
        self::assertSame(array_merge(array_fill(0, self::PROCESSES - 1, 'already_present'), ['recorded']), $outcomes);
        self::assertSame(1, $this->attendanceCount());
    }

    public function test_concurrent_scans_of_different_students_all_record(): void
    {
        [$lessonId, $studentIds] = $this->seed(self::PROCESSES);

        $outcomes = $this->scanConcurrently($lessonId, $studentIds);

        self::assertSame(array_fill(0, self::PROCESSES, 'recorded'), $outcomes);
        self::assertSame(self::PROCESSES, $this->attendanceCount());
    }

    /**
     * @param list<Uuid> $studentIds one child process per entry
     *
     * @return list<string> the outcome printed by each child
     */
    private function scanConcurrently(Uuid $lessonId, array $studentIds): array
    {
        $startAt = (string) (microtime(true) + 1.5);
        $processes = [];
        foreach ($studentIds as $studentId) {
            $process = new Process(
                [\PHP_BINARY, self::CHILD, $lessonId->toRfc4122(), $studentId->toRfc4122(), $startAt],
                \dirname(__DIR__, 3),
                ['APP_ENV' => 'test', 'DATABASE_URL' => 'sqlite:///'.$this->databaseFile],
                timeout: 60,
            );
            $process->start();
            $processes[] = $process;
        }

        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }

        return $outcomes;
    }

    /**
     * A teacher, one course with a scheduled lesson, and the given number of actively
     * enrolled students; plain DBAL inserts (column lists of Version20261110225500).
     *
     * @return array{Uuid, list<Uuid>}
     */
    private function seed(int $students): array
    {
        $now = '2026-03-10 19:00:00';
        $teacherId = Uuid::v7();
        $courseId = Uuid::v7();
        $lessonId = Uuid::v7();
        $studentIds = [];

        $connection = $this->connection();
        try {
            $connection->insert('teacher', [
                'id' => $teacherId->toBinary(), 'name' => 'Teacher', 'email' => 'teacher@example.test',
                'password_hash' => 'x', 'timezone' => 'Europe/Zurich', 'created_at' => $now,
            ]);
            $connection->insert('course', [
                'id' => $courseId->toBinary(), 'name' => 'Salsa', 'timezone' => 'Europe/Zurich', 'weekday' => 2,
                'local_start_time' => '20:00:00', 'duration_minutes' => 60, 'starts_on' => '2026-03-01',
                'created_at' => $now, 'teacher_id' => $teacherId->toBinary(),
            ]);
            $connection->insert('lesson_session', [
                'id' => $lessonId->toBinary(), 'starts_at_utc' => '2026-03-10 19:00:00', 'ends_at_utc' => '2026-03-10 20:00:00',
                'status' => 'scheduled', 'course_id' => $courseId->toBinary(),
            ]);
            for ($i = 1; $i <= $students; ++$i) {
                $studentId = Uuid::v7();
                $connection->insert('student', [
                    'id' => $studentId->toBinary(), 'first_name' => 'Student', 'last_name' => (string) $i,
                    'created_at' => $now, 'teacher_id' => $teacherId->toBinary(),
                ]);
                $connection->insert('enrollment', [
                    'id' => Uuid::v7()->toBinary(), 'joined_at' => $now,
                    'course_id' => $courseId->toBinary(), 'student_id' => $studentId->toBinary(),
                ]);
                $studentIds[] = $studentId;
            }
        } finally {
            $connection->close();
        }

        return [$lessonId, $studentIds];
    }

    private function attendanceCount(): int
    {
        $connection = $this->connection();
        try {
            return (int) $connection->fetchOne('SELECT COUNT(*) FROM attendance');
        } finally {
            $connection->close();
        }
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databaseFile]);
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
}
