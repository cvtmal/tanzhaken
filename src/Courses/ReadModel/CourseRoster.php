<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use App\Account\Entity\Teacher;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The active enrollments of one of the teacher's courses, by last and first name. One query.
 */
final class CourseRoster
{
    private const ROSTER = <<<'SQL'
        SELECT e.id AS enrollment_id, s.id AS student_id, s.first_name, s.last_name, e.public_checkin_label, s.archived_at
        FROM enrollment e
        JOIN course c ON c.id = e.course_id
        JOIN student s ON s.id = e.student_id
        WHERE e.course_id = :course AND c.teacher_id = :teacher AND e.left_at IS NULL
        ORDER BY s.last_name, s.first_name, s.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<RosterRow> empty when the course is unknown or another teacher's
     */
    public function forCourse(Teacher $teacher, Uuid $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::ROSTER,
            ['course' => $courseId, 'teacher' => $teacher->getId()],
            ['course' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(static fn (array $row): RosterRow => new RosterRow(
            enrollmentId: Uuid::fromBinary((string) $row['enrollment_id']),
            studentId: Uuid::fromBinary((string) $row['student_id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            label: null === $row['public_checkin_label'] ? null : (string) $row['public_checkin_label'],
            studentArchived: null !== $row['archived_at'],
        ), $rows);
    }
}
