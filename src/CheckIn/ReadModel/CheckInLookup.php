<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Finds a check-in window by the SHA-256 hash of its token (S8) and returns the facts every
 * public check is made from, in one query. The token is the capability: no teacher filter.
 */
final class CheckInLookup
{
    private const BY_TOKEN_HASH = <<<'SQL'
        SELECT w.id AS window_id, w.opened_at, w.expires_at, w.closed_at,
               l.id AS lesson_id, l.status AS lesson_status, l.starts_at_utc, l.ends_at_utc,
               c.id AS course_id, c.name AS course_name, c.timezone AS course_timezone, c.archived_at AS course_archived_at,
               t.id AS teacher_id, t.name AS teacher_name
        FROM check_in_window w
        JOIN lesson_session l ON l.id = w.lesson_session_id
        JOIN course c ON c.id = l.course_id
        JOIN teacher t ON t.id = c.teacher_id
        WHERE w.token_hash = :hash
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return CheckInFacts|null null when no window has this token hash
     */
    public function byTokenHash(string $hash): ?CheckInFacts
    {
        $row = $this->connection->fetchAssociative(self::BY_TOKEN_HASH, ['hash' => $hash]);
        if (false === $row) {
            return null;
        }

        return new CheckInFacts(
            windowId: Uuid::fromBinary((string) $row['window_id']),
            openedAt: $this->dateTime($row['opened_at']),
            expiresAt: $this->dateTime($row['expires_at']),
            closedAt: null === $row['closed_at'] ? null : $this->dateTime($row['closed_at']),
            lessonId: Uuid::fromBinary((string) $row['lesson_id']),
            lessonStatus: LessonStatus::from((string) $row['lesson_status']),
            lessonStartsAtUtc: $this->dateTime($row['starts_at_utc']),
            lessonEndsAtUtc: $this->dateTime($row['ends_at_utc']),
            courseId: Uuid::fromBinary((string) $row['course_id']),
            courseName: (string) $row['course_name'],
            courseTimezone: (string) $row['course_timezone'],
            courseArchived: null !== $row['course_archived_at'],
            teacherId: Uuid::fromBinary((string) $row['teacher_id']),
            teacherName: (string) $row['teacher_name'],
        );
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
