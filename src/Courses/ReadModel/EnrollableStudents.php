<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use App\Account\Entity\Teacher;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The teacher's students who can be enrolled in one of their courses: not archived (D-035) and
 * without an active enrollment in it. A student who left is offered (enrolling rejoins). One
 * query; empty when the course is unknown or another teacher's.
 */
final class EnrollableStudents
{
    private const STUDENTS = <<<'SQL'
        SELECT s.id, s.first_name, s.last_name
        FROM student s
        WHERE s.teacher_id = :teacher AND s.archived_at IS NULL
          AND EXISTS (SELECT 1 FROM course c WHERE c.id = :course AND c.teacher_id = :teacher)
          AND NOT EXISTS (SELECT 1 FROM enrollment e WHERE e.course_id = :course AND e.student_id = s.id AND e.left_at IS NULL)
        ORDER BY s.last_name, s.first_name, s.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<StudentOption>
     */
    public function forCourse(Teacher $teacher, Uuid $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::STUDENTS,
            ['course' => $courseId, 'teacher' => $teacher->getId()],
            ['course' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(static fn (array $row): StudentOption => new StudentOption(
            id: Uuid::fromBinary((string) $row['id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
        ), $rows);
    }
}
