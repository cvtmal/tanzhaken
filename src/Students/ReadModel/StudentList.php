<?php

declare(strict_types=1);

namespace App\Students\ReadModel;

use App\Account\Entity\Teacher;
use App\Students\Entity\Student;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The teacher's student directory in one query: active or archived students, optionally
 * filtered by a name search (first, last or full name, case-insensitive, `%` and `_` taken
 * literally), ordered by last then first name, each with the number of active enrollments in
 * non-archived courses. Course data is read with SQL of its own (S2: Students may not use Courses).
 */
final class StudentList
{
    /** Longest useful search: first name + space + last name. */
    public const SEARCH_MAX = 2 * Student::NAME_MAX + 1;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<StudentListItem>
     */
    public function forTeacher(Teacher $teacher, string $search = '', bool $archived = false): array
    {
        $params = ['teacher' => $teacher->getId()];
        $types = ['teacher' => UuidType::NAME];

        $where = $archived ? 's.archived_at IS NOT NULL' : 's.archived_at IS NULL';
        $search = self::normaliseSearch($search);
        if ('' !== $search) {
            $fullName = $this->connection->getDatabasePlatform()->getConcatExpression('s.first_name', "' '", 's.last_name');
            $where .= " AND (LOWER(s.first_name) LIKE :q ESCAPE '!' OR LOWER(s.last_name) LIKE :q ESCAPE '!'"
                ." OR LOWER({$fullName}) LIKE :q ESCAPE '!')";
            $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        }

        $sql = <<<SQL
            SELECT s.id, s.first_name, s.last_name, s.email, s.archived_at, COUNT(e.id) AS active_courses
            FROM student s
            LEFT JOIN enrollment e ON e.student_id = s.id AND e.left_at IS NULL
                AND EXISTS (SELECT 1 FROM course c WHERE c.id = e.course_id AND c.archived_at IS NULL)
            WHERE s.teacher_id = :teacher AND {$where}
            GROUP BY s.id, s.first_name, s.last_name, s.email, s.archived_at
            ORDER BY LOWER(s.last_name), LOWER(s.first_name), s.id
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return array_map(fn (array $row): StudentListItem => new StudentListItem(
            id: Uuid::fromBinary((string) $row['id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            email: null === $row['email'] ? null : (string) $row['email'],
            archivedAt: $this->nullableDateTime($row['archived_at']),
            activeCourseCount: (int) $row['active_courses'],
        ), $rows);
    }

    /** Trimmed, lower-cased, cut to SEARCH_MAX characters. */
    public static function normaliseSearch(string $search): string
    {
        return mb_substr(mb_strtolower(trim($search)), 0, self::SEARCH_MAX);
    }

    private function nullableDateTime(mixed $value): ?\DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $dateTime instanceof \DateTimeImmutable ? $dateTime : null;
    }
}
