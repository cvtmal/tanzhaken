<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The students of a course who may check in: enrollments not left, students not archived
 * (D-035: an archived student is excluded even while still enrolled). One query, ordered by
 * joined_at then enrollment id. No email, no notes.
 */
final class CheckInRoster
{
    private const FOR_COURSE = <<<'SQL'
        SELECT e.id AS enrollment_id, s.id AS student_id, s.first_name, s.last_name,
               e.public_checkin_label, e.joined_at
        FROM enrollment e
        JOIN student s ON s.id = e.student_id
        WHERE e.course_id = :course AND e.left_at IS NULL AND s.archived_at IS NULL
        ORDER BY e.joined_at, e.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<RosterEntry>
     */
    public function forCourse(Uuid $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::FOR_COURSE,
            ['course' => $courseId],
            ['course' => UuidType::NAME],
        );

        return array_map(fn (array $row): RosterEntry => new RosterEntry(
            enrollmentId: Uuid::fromBinary((string) $row['enrollment_id']),
            studentId: Uuid::fromBinary((string) $row['student_id']),
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            publicCheckinLabel: null === $row['public_checkin_label'] ? null : (string) $row['public_checkin_label'],
            joinedAt: $this->dateTime($row['joined_at']),
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
