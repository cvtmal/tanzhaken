<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A student's attendance marks across the teacher's courses, newest lesson first, in one
 * query. An unknown student or one of another teacher gives []: callers decide the 404 with
 * StudentRepository::findOwned(). Credits per course are #12's (Balances).
 */
final class StudentAttendanceHistory
{
    private const HISTORY = <<<'SQL'
        SELECT a.lesson_session_id, c.id AS course_id, c.name, c.timezone, l.starts_at_utc, l.status AS lesson_status,
               a.status, a.source, a.marked_at
        FROM attendance a
        JOIN lesson_session l ON l.id = a.lesson_session_id
        JOIN course c ON c.id = l.course_id
        WHERE a.student_id = :student AND c.teacher_id = :teacher
        ORDER BY l.starts_at_utc DESC, l.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<StudentAttendanceRow>
     */
    public function forStudent(Teacher $teacher, Uuid $studentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::HISTORY,
            ['student' => $studentId, 'teacher' => $teacher->getId()],
            ['student' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(fn (array $row): StudentAttendanceRow => new StudentAttendanceRow(
            lessonId: Uuid::fromBinary((string) $row['lesson_session_id']),
            courseId: Uuid::fromBinary((string) $row['course_id']),
            courseName: (string) $row['name'],
            courseTimezone: (string) $row['timezone'],
            startsAtUtc: $this->dateTime($row['starts_at_utc']),
            lessonStatus: LessonStatus::from((string) $row['lesson_status']),
            status: AttendanceStatus::from((string) $row['status']),
            source: AttendanceSource::from((string) $row['source']),
            markedAt: $this->dateTime($row['marked_at']),
        ), $rows);
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
        if (!$dateTime instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('A NOT NULL datetime column was null.');
        }

        return $dateTime;
    }
}
