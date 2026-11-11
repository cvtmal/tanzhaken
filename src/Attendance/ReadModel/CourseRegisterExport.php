<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The register of one course for the CSV export (#15), in one query.
 * Lessons: not cancelled, and started by now or already carrying a mark (D1), chronological.
 * Students per lesson: anyone with a mark, plus active enrolments that joined before the lesson ended (D2).
 * A foreign or unknown course gives [] (the controller answers 404 before).
 */
final class CourseRegisterExport
{
    private const REGISTER = <<<'SQL'
        SELECT l.id AS lesson_id, l.starts_at_utc, s.id AS student_id, s.first_name, s.last_name, a.status
        FROM lesson_session l
        JOIN course c ON c.id = l.course_id
        JOIN enrollment e ON e.course_id = l.course_id
        JOIN student s ON s.id = e.student_id
        LEFT JOIN attendance a ON a.lesson_session_id = l.id AND a.student_id = s.id
        WHERE l.course_id = :course AND c.teacher_id = :teacher AND l.status <> :cancelled
          AND (l.starts_at_utc <= :now OR EXISTS (SELECT 1 FROM attendance x WHERE x.lesson_session_id = l.id))
          AND (a.id IS NOT NULL OR (e.left_at IS NULL AND e.joined_at <= l.ends_at_utc))
        ORDER BY l.starts_at_utc, l.id, s.last_name, s.first_name, s.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<CourseRegisterExportRow>
     */
    public function forCourse(Teacher $teacher, Uuid $courseId, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::REGISTER,
            [
                'course' => $courseId,
                'teacher' => $teacher->getId(),
                'cancelled' => LessonStatus::Cancelled->value,
                'now' => $now->setTimezone(new \DateTimeZone('UTC')),
            ],
            [
                'course' => UuidType::NAME,
                'teacher' => UuidType::NAME,
                'now' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return array_map(fn (array $row): CourseRegisterExportRow => new CourseRegisterExportRow(
            lessonId: Uuid::fromBinary((string) $row['lesson_id']),
            startsAtUtc: $this->dateTime($row['starts_at_utc']),
            studentId: Uuid::fromBinary((string) $row['student_id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            status: null === $row['status'] ? null : AttendanceStatus::from((string) $row['status']),
        ), $rows);
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
        if (!$dateTime instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('A lesson has no start time.');
        }

        return $dateTime;
    }
}
