<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What the teacher's check-in page and the lesson-page slot show, one query per method.
 *
 * counts(): the students who may check in (the CheckInRoster::forCourse set: enrollment not
 * left, student not archived) and how many of them are present for the lesson, whatever the
 * source. openUntil(): the expiry of the lesson's active window, scoped to the teacher.
 */
final class CheckInProgress
{
    private const COUNTS = <<<'SQL'
        SELECT COUNT(*) AS enrolled,
               COALESCE(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END), 0) AS checked_in
        FROM enrollment e
        JOIN student s ON s.id = e.student_id
        LEFT JOIN attendance a ON a.lesson_session_id = :lesson AND a.student_id = s.id
        WHERE e.course_id = :course AND e.left_at IS NULL AND s.archived_at IS NULL
        SQL;

    private const OPEN_UNTIL = <<<'SQL'
        SELECT MAX(w.expires_at)
        FROM check_in_window w
        JOIN lesson_session ls ON ls.id = w.lesson_session_id
        JOIN course c ON c.id = ls.course_id
        WHERE w.lesson_session_id = :lesson AND c.teacher_id = :teacher
          AND w.closed_at IS NULL AND w.expires_at > :now
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function counts(Uuid $lessonId, Uuid $courseId): CheckInCounts
    {
        $row = $this->connection->fetchAssociative(
            self::COUNTS,
            ['lesson' => $lessonId, 'course' => $courseId],
            ['lesson' => UuidType::NAME, 'course' => UuidType::NAME],
        );

        if (false === $row) {
            return new CheckInCounts(checkedIn: 0, enrolled: 0);
        }

        return new CheckInCounts(
            checkedIn: (int) $row['checked_in'],
            enrolled: (int) $row['enrolled'],
        );
    }

    public function openUntil(Uuid $teacherId, Uuid $lessonId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            self::OPEN_UNTIL,
            [
                'lesson' => $lessonId,
                'teacher' => $teacherId,
                'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
            ['lesson' => UuidType::NAME, 'teacher' => UuidType::NAME],
        );
        if (null === $value || false === $value) {
            return null;
        }

        $expiresAt = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $expiresAt instanceof \DateTimeImmutable ? $expiresAt->setTimezone(new \DateTimeZone('UTC')) : null;
    }
}
