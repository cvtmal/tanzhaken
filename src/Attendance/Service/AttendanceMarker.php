<?php

declare(strict_types=1);

namespace App\Attendance\Service;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\Attendance;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\AttendanceNotFound;
use App\Attendance\Exception\InvalidAttendance;
use App\Attendance\Repository\AttendanceRepository;
use App\Courses\Entity\LessonSession;
use App\Courses\Repository\LessonSessionRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use App\Students\Entity\Student;
use App\Students\Repository\StudentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The teacher's marking of a lesson's attendance (REG-2). Every lookup and every refusal
 * happens before the transaction (S2, D-027: Doctrine closes the EntityManager on any
 * exception inside wrapInTransaction()); inside it only the write, the flush and the audit
 * event. The transaction therefore starts with its first write, so SQLite's busy handler
 * applies.
 */
final class AttendanceMarker
{
    public const REASON_MAX_LENGTH = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LessonSessionRepository $lessons,
        private readonly StudentRepository $students,
        private readonly AttendanceRepository $attendances,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Marks the student present or absent at the lesson as the teacher; a mark with the
     * status the row already has is a no-op (no write, no audit). Future lessons may be
     * marked (D6). A student's scan is overridden (source becomes teacher).
     *
     * @throws AttendanceNotFound when the lesson or the student is unknown or of another teacher
     * @throws InvalidAttendance  when the reason is too long, the lesson is cancelled or the student
     *                            is not actively enrolled in its course; changedConcurrently() when
     *                            a scan inserted the row at the same time: the EntityManager is
     *                            closed afterwards, so the caller redirects (fresh request)
     */
    public function mark(Teacher $teacher, Uuid $lessonId, Uuid $studentId, AttendanceStatus $status, ?string $reason = null): void
    {
        [$lesson, $student] = $this->findOwned($teacher, $lessonId, $studentId);
        $reason = $this->normaliseReason($reason);
        if ($lesson->isCancelled()) {
            throw InvalidAttendance::lessonCancelled();
        }
        if (!$this->attendances->hasActiveEnrollment($lesson->getCourse()->getId(), $student->getId())) {
            throw InvalidAttendance::studentNotEnrolled();
        }

        $existing = $this->attendances->findOneForLessonAndStudent($lesson, $student);
        if ($existing?->getStatus() === $status) {
            return;
        }
        $previousStatus = $existing?->getStatus();
        $previousSource = $existing?->getSource();
        $now = $this->clock->now();

        try {
            $this->em->wrapInTransaction(function () use ($teacher, $lesson, $student, $status, $reason, $existing, $previousStatus, $previousSource, $now): void {
                if (null === $existing) {
                    $attendance = new Attendance($lesson, $student, $status, AttendanceSource::Teacher, $now);
                    $this->em->persist($attendance);
                } else {
                    $attendance = $existing;
                    $attendance->change($status, AttendanceSource::Teacher, $now);
                }
                $this->em->flush();

                $this->audit->record($teacher->getId(), ActorType::Teacher, 'attendance.marked', 'attendance', $attendance->getId(), [
                    'lesson_id' => $lesson->getId()->toRfc4122(),
                    'student_id' => $student->getId()->toRfc4122(),
                    'status' => $status->value,
                    'previous_status' => $previousStatus?->value,
                    'previous_source' => $previousSource?->value,
                    'reason' => $reason,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw InvalidAttendance::changedConcurrently($e);
        }
    }

    /**
     * Removes the student's mark ("Not marked" again). Always allowed, also on a cancelled
     * lesson or a left enrollment (D2).
     *
     * @throws AttendanceNotFound when the lesson or the student is unknown or of another teacher
     * @throws InvalidAttendance  when the reason is too long or the student has no mark
     */
    public function clear(Teacher $teacher, Uuid $lessonId, Uuid $studentId, ?string $reason = null): void
    {
        [$lesson, $student] = $this->findOwned($teacher, $lessonId, $studentId);
        $reason = $this->normaliseReason($reason);
        $attendance = $this->attendances->findOneForLessonAndStudent($lesson, $student);
        if (null === $attendance) {
            throw InvalidAttendance::notMarked();
        }
        $id = $attendance->getId();
        $previousStatus = $attendance->getStatus();
        $previousSource = $attendance->getSource();

        $this->em->wrapInTransaction(function () use ($teacher, $lesson, $student, $reason, $attendance, $id, $previousStatus, $previousSource): void {
            $this->em->remove($attendance);
            $this->em->flush();

            $this->audit->record($teacher->getId(), ActorType::Teacher, 'attendance.cleared', 'attendance', $id, [
                'lesson_id' => $lesson->getId()->toRfc4122(),
                'student_id' => $student->getId()->toRfc4122(),
                'previous_status' => $previousStatus->value,
                'previous_source' => $previousSource->value,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * @return array{LessonSession, Student}
     */
    private function findOwned(Teacher $teacher, Uuid $lessonId, Uuid $studentId): array
    {
        $lesson = $this->lessons->findOwned($teacher, $lessonId) ?? throw AttendanceNotFound::lesson($lessonId);
        $student = $this->students->findOwned($teacher, $studentId) ?? throw AttendanceNotFound::student($studentId);

        return [$lesson, $student];
    }

    private function normaliseReason(?string $reason): ?string
    {
        $reason = null === $reason ? '' : trim($reason);
        if ('' === $reason) {
            return null;
        }
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw InvalidAttendance::reasonTooLong(self::REASON_MAX_LENGTH);
        }

        return $reason;
    }
}
