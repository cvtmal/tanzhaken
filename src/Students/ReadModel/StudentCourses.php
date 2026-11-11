<?php

declare(strict_types=1);

namespace App\Students\ReadModel;

use App\Account\Entity\Teacher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A student's enrollments in the teacher's courses, current ones first, in one query. Left
 * enrollments stay (their history is kept, STU-3). Course data is read with SQL of its own
 * (S2: Students may not use Courses). An unknown student or one of another teacher gives []:
 * callers decide the 404 with StudentRepository::findOwned().
 */
final class StudentCourses
{
    private const COURSES = <<<'SQL'
        SELECT c.id, c.name, c.weekday, c.local_start_time, c.timezone, c.location, c.archived_at,
               e.joined_at, e.left_at
        FROM enrollment e
        JOIN course c ON c.id = e.course_id
        WHERE e.student_id = :student AND c.teacher_id = :teacher
        ORDER BY CASE WHEN e.left_at IS NULL AND c.archived_at IS NULL THEN 0 ELSE 1 END,
                 c.weekday, c.local_start_time, LOWER(c.name), c.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<StudentCourseRow>
     */
    public function forStudent(Teacher $teacher, Uuid $studentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COURSES,
            ['student' => $studentId, 'teacher' => $teacher->getId()],
            ['student' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );

        return array_map(fn (array $row): StudentCourseRow => new StudentCourseRow(
            courseId: Uuid::fromBinary((string) $row['id']),
            name: (string) $row['name'],
            weekday: (int) $row['weekday'],
            localStartTime: $this->convert(Types::TIME_IMMUTABLE, $row['local_start_time'])
                ?? throw new \UnexpectedValueException('A NOT NULL time column was null.'),
            timezone: (string) $row['timezone'],
            location: null === $row['location'] ? null : (string) $row['location'],
            courseArchived: null !== $row['archived_at'],
            joinedAt: $this->convert(Types::DATETIME_IMMUTABLE, $row['joined_at'])
                ?? throw new \UnexpectedValueException('A NOT NULL datetime column was null.'),
            leftAt: $this->convert(Types::DATETIME_IMMUTABLE, $row['left_at']),
        ), $rows);
    }

    private function convert(string $type, mixed $value): ?\DateTimeImmutable
    {
        $dateTime = Type::getType($type)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $dateTime instanceof \DateTimeImmutable ? $dateTime : null;
    }
}
