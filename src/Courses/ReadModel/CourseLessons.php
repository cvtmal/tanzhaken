<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use App\Account\Entity\Teacher;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every lesson of one of the teacher's courses in chronological order (CRS-5), with how many
 * students were marked and how many present. The attendance counts come from Courses' own SQL,
 * so Courses does not depend on Attendance (S2). One query, no N+1.
 */
final class CourseLessons
{
    private const LESSONS = <<<'SQL'
        SELECT l.id, l.starts_at_utc, l.ends_at_utc, l.status,
               COALESCE(SUM(CASE WHEN a.status = :present THEN 1 ELSE 0 END), 0) AS present_count,
               COUNT(a.id) AS marked_count
        FROM lesson_session l
        JOIN course c ON c.id = l.course_id
        LEFT JOIN attendance a ON a.lesson_session_id = l.id
        WHERE l.course_id = :course AND c.teacher_id = :teacher
        GROUP BY l.id, l.starts_at_utc, l.ends_at_utc, l.status
        ORDER BY l.starts_at_utc, l.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<LessonRow> empty when the course is unknown or another teacher's
     */
    public function forCourse(Teacher $teacher, Uuid $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::LESSONS,
            ['course' => $courseId, 'teacher' => $teacher->getId(), 'present' => 'present'],
            ['course' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(fn (array $row): LessonRow => new LessonRow(
            id: Uuid::fromBinary((string) $row['id']),
            startsAtUtc: $this->dateTime($row['starts_at_utc']),
            endsAtUtc: $this->dateTime($row['ends_at_utc']),
            status: LessonStatus::from((string) $row['status']),
            presentCount: (int) $row['present_count'],
            markedCount: (int) $row['marked_count'],
        ), $rows);
    }

    /**
     * The "Next lesson" card (E1/E3): the first non-cancelled lesson that has not ended yet.
     *
     * @param list<LessonRow> $lessons in chronological order
     */
    public static function next(array $lessons, \DateTimeImmutable $now): ?LessonRow
    {
        foreach ($lessons as $lesson) {
            if (LessonStatus::Cancelled !== $lesson->status && $lesson->endsAtUtc >= $now) {
                return $lesson;
            }
        }

        return null;
    }

    /**
     * The "Previous" card (E1/E3): the last non-cancelled lesson that has ended.
     *
     * @param list<LessonRow> $lessons in chronological order
     */
    public static function previous(array $lessons, \DateTimeImmutable $now): ?LessonRow
    {
        $previous = null;
        foreach ($lessons as $lesson) {
            if (LessonStatus::Cancelled !== $lesson->status && $lesson->endsAtUtc < $now) {
                $previous = $lesson;
            }
        }

        return $previous;
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $dateTime instanceof \DateTimeImmutable ? $dateTime : throw new \UnexpectedValueException('A lesson has no start or end.');
    }
}
