<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A lesson's register: every actively enrolled student of the lesson's course plus every
 * student with a mark for the lesson (also after leaving), ordered by last and first name.
 * Two queries per page (ownership, roster), never N+1; #13 merges the balances from
 * App\Credits\ReadModel\Balances by enrollmentId.
 */
final class LessonRegister
{
    private const OWNED_LESSON = <<<'SQL'
        SELECT l.course_id
        FROM lesson_session l
        JOIN course c ON c.id = l.course_id
        WHERE l.id = :lesson AND c.teacher_id = :teacher
        SQL;

    private const ROSTER = <<<'SQL'
        SELECT s.id AS student_id, e.id AS enrollment_id, s.first_name, s.last_name, e.public_checkin_label,
               s.archived_at, e.left_at, a.status, a.source, a.marked_at
        FROM student s
        LEFT JOIN enrollment e ON e.course_id = :course AND e.student_id = s.id
        LEFT JOIN attendance a ON a.lesson_session_id = :lesson AND a.student_id = s.id
        WHERE s.teacher_id = :teacher AND ((e.id IS NOT NULL AND e.left_at IS NULL) OR a.id IS NOT NULL)
        ORDER BY s.last_name, s.first_name, s.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<LessonRegisterRow>|null null when the lesson is unknown or of another teacher
     */
    public function forLesson(Teacher $teacher, Uuid $lessonId): ?array
    {
        $courseId = $this->connection->fetchOne(
            self::OWNED_LESSON,
            ['lesson' => $lessonId, 'teacher' => $teacher->getId()],
            ['lesson' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );
        if (false === $courseId) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            self::ROSTER,
            ['course' => Uuid::fromBinary((string) $courseId), 'lesson' => $lessonId, 'teacher' => $teacher->getId()],
            ['course' => UuidType::NAME, 'lesson' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(fn (array $row): LessonRegisterRow => new LessonRegisterRow(
            studentId: Uuid::fromBinary((string) $row['student_id']),
            enrollmentId: null === $row['enrollment_id'] ? null : Uuid::fromBinary((string) $row['enrollment_id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            publicCheckinLabel: null === $row['public_checkin_label'] ? null : (string) $row['public_checkin_label'],
            studentArchived: null !== $row['archived_at'],
            enrollmentActive: null !== $row['enrollment_id'] && null === $row['left_at'],
            status: null === $row['status'] ? null : AttendanceStatus::from((string) $row['status']),
            source: null === $row['source'] ? null : AttendanceSource::from((string) $row['source']),
            markedAt: $this->dateTime($row['marked_at']),
        ), $rows);
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $dateTime instanceof \DateTimeImmutable ? $dateTime : null;
    }
}
