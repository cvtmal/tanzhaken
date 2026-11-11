<?php

declare(strict_types=1);

namespace App\Dashboard\ReadModel;

use App\Account\Entity\Teacher;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The dashboard's lessons (#17, P1): today's (from the start of today to the start of tomorrow in
 * the teacher's zone) and the upcoming ones up to the start of today + 8 days, of the teacher's
 * non-archived courses, cancelled ones included (P2). One query; a second, tiny one only when the
 * first returns no row, to tell "no course" from "no lesson" (P10). At most 2 queries (S2).
 */
final class TodaysLessons
{
    /** At most this many upcoming lessons; today's are never cut. */
    public const UPCOMING_LIMIT = 10;

    /** Days after today that count as upcoming. */
    public const UPCOMING_DAYS = 7;

    private const SQL = <<<'SQL'
        SELECT ls.id, ls.starts_at_utc, ls.ends_at_utc, ls.status,
               c.id AS course_id, c.name, c.location, c.timezone, c.duration_minutes,
               (SELECT COUNT(*) FROM enrollment e
                  INNER JOIN student s ON s.id = e.student_id
                  WHERE e.course_id = c.id AND e.left_at IS NULL AND s.archived_at IS NULL) AS student_count,
               EXISTS (SELECT 1 FROM check_in_window w
                  WHERE w.lesson_session_id = ls.id AND w.closed_at IS NULL AND w.expires_at > :now) AS checkin_open
        FROM lesson_session ls
        INNER JOIN course c ON c.id = ls.course_id
        WHERE c.teacher_id = :teacher AND c.archived_at IS NULL
          AND ls.starts_at_utc >= :from AND ls.starts_at_utc < :until
        ORDER BY ls.starts_at_utc, c.name, ls.id
        SQL;

    private const HAS_COURSES_SQL = 'SELECT COUNT(*) FROM course WHERE teacher_id = :teacher AND archived_at IS NULL';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forTeacher(Teacher $teacher): TodayBoard
    {
        $utc = new \DateTimeZone('UTC');
        $now = $this->clock->now()->setTimezone($utc);
        $startOfToday = $now->setTimezone(new \DateTimeZone($teacher->getTimezone()))->setTime(0, 0);
        $from = $startOfToday->setTimezone($utc);
        $tomorrow = $startOfToday->modify('+1 day')->setTimezone($utc);
        $until = $startOfToday->modify(\sprintf('+%d days', self::UPCOMING_DAYS + 1))->setTimezone($utc);

        $rows = $this->connection->fetchAllAssociative(self::SQL, [
            'teacher' => $teacher->getId(),
            'now' => $now->format('Y-m-d H:i:s'),
            'from' => $from->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
        ], ['teacher' => UuidType::NAME]);

        $today = [];
        $upcoming = [];
        foreach ($rows as $row) {
            $lesson = $this->hydrate($row);
            if ($lesson->startsAtUtc < $tomorrow) {
                $today[] = $lesson;
            } elseif (\count($upcoming) < self::UPCOMING_LIMIT) {
                $upcoming[] = $lesson;
            }
        }

        $hasCourses = [] !== $rows
            || (int) $this->connection->fetchOne(self::HAS_COURSES_SQL, ['teacher' => $teacher->getId()], ['teacher' => UuidType::NAME]) > 0;

        return new TodayBoard($today, $upcoming, $now, $hasCourses);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DashboardLesson
    {
        // Integers and booleans cast explicitly: MariaDB/PDO may return numeric strings.
        return new DashboardLesson(
            lessonId: Uuid::fromBinary((string) $row['id']),
            courseId: Uuid::fromBinary((string) $row['course_id']),
            courseName: (string) $row['name'],
            location: null === $row['location'] ? null : (string) $row['location'],
            timezone: (string) $row['timezone'],
            durationMinutes: (int) $row['duration_minutes'],
            startsAtUtc: $this->dateTime($row['starts_at_utc']),
            endsAtUtc: $this->dateTime($row['ends_at_utc']),
            status: LessonStatus::from((string) $row['status']),
            studentCount: (int) $row['student_count'],
            checkInOpen: 1 === (int) $row['checkin_open'],
        );
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
        if (!$dateTime instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException('A lesson time is missing.');
        }

        return $dateTime;
    }
}
