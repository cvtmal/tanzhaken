<?php

declare(strict_types=1);

namespace App\Attendance\Service;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\InvalidAttendance;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The race-safe insert of a student's scan (S8, PERF-2): one INSERT … SELECT that only
 * writes when the lesson is not cancelled and the student is actively enrolled in its
 * course; the unique key (lesson_session_id, student_id) decides every duplicate. There
 * is no read before the insert.
 *
 * Call it outside a transaction, or as the first write of one, so SQLite's busy handler
 * applies. #8 runs S8's window checks first. No audit event is written (research D3).
 */
final class CheckInRecorder
{
    private const INSERT = <<<'SQL'
        INSERT INTO attendance (id, lesson_session_id, student_id, status, source, marked_at, updated_at)
        SELECT :id, l.id, e.student_id, :present, :source, :marked_at, :updated_at
        FROM lesson_session l
        JOIN enrollment e ON e.course_id = l.course_id
        WHERE l.id = :lesson AND l.status <> :cancelled AND e.student_id = :student AND e.left_at IS NULL
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidAttendance when the lesson is unknown or cancelled, or the student is not
     *                           (or no longer) enrolled in its course
     */
    public function recordCheckIn(Uuid $lessonId, Uuid $studentId): CheckInOutcome
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $inserted = $this->insert($lessonId, $studentId);
            } catch (UniqueConstraintViolationException $e) {
                $status = $this->connection->fetchOne(
                    'SELECT status FROM attendance WHERE lesson_session_id = :lesson AND student_id = :student',
                    ['lesson' => $lessonId, 'student' => $studentId],
                    ['lesson' => UuidType::NAME, 'student' => UuidType::NAME],
                );
                if (false === $status) {
                    // The row was cleared by the teacher between our insert and this read: try once more.
                    if ($attempt >= 2) {
                        throw $e;
                    }
                    continue;
                }

                return AttendanceStatus::Present->value === $status
                    ? CheckInOutcome::AlreadyPresent
                    : CheckInOutcome::MarkedByTeacher;
            }

            if (0 === $inserted) {
                throw InvalidAttendance::notEligibleForCheckIn();
            }

            return CheckInOutcome::Recorded;
        }
    }

    private function insert(Uuid $lessonId, Uuid $studentId): int
    {
        $now = $this->clock->now();

        return (int) $this->connection->executeStatement(self::INSERT, [
            'id' => Uuid::v7(),
            'present' => AttendanceStatus::Present->value,
            'source' => AttendanceSource::StudentQr->value,
            'marked_at' => $now,
            'updated_at' => $now,
            'lesson' => $lessonId,
            'cancelled' => LessonStatus::Cancelled->value,
            'student' => $studentId,
        ], [
            'id' => UuidType::NAME,
            'marked_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
            'lesson' => UuidType::NAME,
            'student' => UuidType::NAME,
        ]);
    }
}
