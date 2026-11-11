<?php

declare(strict_types=1);

namespace App\Credits\ReadModel;

use App\Account\Entity\Teacher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A student's credit grants across the teacher's courses (STU-4), voided ones included, in one
 * query. An unknown student or one of another teacher gives []. The balance stays with Balances.
 */
final class CreditHistory
{
    private const HISTORY = <<<'SQL'
        SELECT g.id, g.enrollment_id, g.quantity, g.granted_at, g.note, g.voided_at
        FROM credit_grant g
        INNER JOIN enrollment e ON e.id = g.enrollment_id
        INNER JOIN course c ON c.id = e.course_id
        WHERE c.teacher_id = :teacher AND e.student_id = :student
        ORDER BY g.granted_at DESC, g.id DESC
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The student's grants grouped by enrollment id (RFC 4122), newest effective date first (then
     * newest id). An enrollment without grants has no key.
     *
     * @return array<string, list<GrantRow>>
     */
    public function forStudent(Teacher $teacher, Uuid $studentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::HISTORY,
            ['teacher' => $teacher->getId(), 'student' => $studentId],
            ['teacher' => UuidType::NAME, 'student' => UuidType::NAME],
        );

        $byEnrollment = [];
        foreach ($rows as $row) {
            $grant = new GrantRow(
                grantId: Uuid::fromBinary((string) $row['id']),
                enrollmentId: Uuid::fromBinary((string) $row['enrollment_id']),
                // Cast explicitly: MariaDB/PDO may return numeric strings.
                quantity: (int) $row['quantity'],
                grantedAt: $this->dateTime($row['granted_at']) ?? throw new \UnexpectedValueException('A NOT NULL datetime column was null.'),
                note: null === $row['note'] ? null : (string) $row['note'],
                voidedAt: $this->dateTime($row['voided_at']),
            );
            $byEnrollment[$grant->enrollmentId->toRfc4122()][] = $grant;
        }

        return $byEnrollment;
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $dateTime instanceof \DateTimeImmutable ? $dateTime : null;
    }
}
