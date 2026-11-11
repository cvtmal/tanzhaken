<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use App\Account\Entity\Teacher;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The teacher's course list (C1): active courses in weekday, start time and name order, each with
 * its number of active enrollments and its next lesson. One query, no N+1.
 */
final class CourseOverview
{
    private const CARDS = <<<'SQL'
        SELECT c.id, c.name, c.weekday, c.local_start_time, c.duration_minutes, c.location, c.timezone,
               c.starts_on, c.ends_on,
               (SELECT COUNT(*) FROM enrollment e WHERE e.course_id = c.id AND e.left_at IS NULL) AS student_count,
               (SELECT MIN(l.starts_at_utc) FROM lesson_session l
                WHERE l.course_id = c.id AND l.status <> :cancelled AND l.starts_at_utc >= :now) AS next_lesson_at
        FROM course c
        WHERE c.teacher_id = :teacher AND c.archived_at IS NULL
        ORDER BY c.weekday, c.local_start_time, c.name, c.id
        SQL;

    private const LAST_LOCATION = <<<'SQL'
        SELECT location
        FROM course
        WHERE teacher_id = :teacher AND archived_at IS NULL AND location IS NOT NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 1
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CourseCard>
     */
    public function forTeacher(Teacher $teacher): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $rows = $this->connection->fetchAllAssociative(
            self::CARDS,
            ['teacher' => $teacher->getId(), 'cancelled' => LessonStatus::Cancelled->value, 'now' => $now],
            ['teacher' => UuidType::NAME, 'now' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(function (array $row) use ($now): CourseCard {
            $timezone = (string) $row['timezone'];
            $today = $now->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
            $startsOn = $this->value(Types::DATE_IMMUTABLE, $row['starts_on']) ?? throw new \UnexpectedValueException('A course has no starts_on.');
            $endsOn = $this->value(Types::DATE_IMMUTABLE, $row['ends_on']);
            $startTime = $this->value(Types::TIME_IMMUTABLE, $row['local_start_time']) ?? throw new \UnexpectedValueException('A course has no local_start_time.');
            $weekday = (int) $row['weekday'];

            return new CourseCard(
                id: Uuid::fromBinary((string) $row['id']),
                name: (string) $row['name'],
                weekday: $weekday,
                localStartTime: $startTime->format('H:i'),
                durationMinutes: (int) $row['duration_minutes'],
                location: null === $row['location'] ? null : (string) $row['location'],
                timezone: $timezone,
                studentCount: (int) $row['student_count'],
                nextLessonAtUtc: $this->value(Types::DATETIME_IMMUTABLE, $row['next_lesson_at']),
                startsOn: $startsOn,
                isToday: (int) $now->setTimezone(new \DateTimeZone($timezone))->format('N') === $weekday
                    && $startsOn->format('Y-m-d') <= $today
                    && (null === $endsOn || $endsOn->format('Y-m-d') >= $today),
                startsLater: $startsOn->format('Y-m-d') > $today,
            );
        }, $rows);
    }

    /**
     * The location of the teacher's most recently created active course that has one, to prefill
     * the new-course form (D9).
     */
    public function lastLocation(Teacher $teacher): ?string
    {
        $location = $this->connection->fetchOne(self::LAST_LOCATION, ['teacher' => $teacher->getId()], ['teacher' => UuidType::NAME]);

        return false === $location || null === $location ? null : (string) $location;
    }

    private function value(string $type, mixed $value): ?\DateTimeImmutable
    {
        $converted = Type::getType($type)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return $converted instanceof \DateTimeImmutable ? $converted : null;
    }
}
