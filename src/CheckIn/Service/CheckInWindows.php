<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use App\Account\Entity\Teacher;
use App\CheckIn\Entity\CheckInWindow;
use App\CheckIn\Exception\CheckInNotFound;
use App\CheckIn\Exception\InvalidCheckInWindow;
use App\CheckIn\ReadModel\CheckInLookup;
use App\CheckIn\Repository\CheckInWindowRepository;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Courses\Repository\LessonSessionRepository;
use App\Courses\Service\LessonScheduler;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The check-in windows of a lesson (S8): open (which completes the lesson and always rotates the
 * token: reopening is calling open() again), close, and resolve a public token to the lesson or
 * one typed refusal.
 *
 * Every refusal is thrown before the transaction (S2, D-027), so the EntityManager stays open.
 * The UPDATE that closes earlier windows is the first statement of each transaction: on SQLite it
 * takes the write lock, so two concurrent opens of one lesson serialise and at most one window
 * stays open (research D5; #3 D4: not a database constraint).
 */
final class CheckInWindows
{
    public const DURATION_MINUTES = 60;

    private const CLOSE_OPEN = 'UPDATE check_in_window SET closed_at = :now WHERE lesson_session_id = :lesson AND closed_at IS NULL';
    private const CLOSE_ACTIVE = self::CLOSE_OPEN.' AND expires_at > :now';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LessonSessionRepository $lessons,
        private readonly CheckInWindowRepository $windows,
        private readonly CheckInLookup $lookup,
        private readonly CheckInTokens $tokens,
        private readonly LessonScheduler $scheduler,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Opens a new window of DURATION_MINUTES for the teacher's lesson, closes every earlier one and
     * marks the lesson completed (S8).
     *
     * @throws CheckInNotFound      when the lesson is unknown or another teacher's
     * @throws InvalidCheckInWindow when the course is archived or the lesson cancelled
     */
    public function open(Teacher $teacher, Uuid $lessonId): OpenedCheckIn
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        if ($lesson->getCourse()->isArchived()) {
            throw InvalidCheckInWindow::courseArchived();
        }
        if (LessonStatus::Cancelled === $lesson->getStatus()) {
            throw InvalidCheckInWindow::lessonCancelled();
        }
        $now = $this->clock->now();
        $token = $this->tokens->issue();
        $expiresAt = $now->modify(\sprintf('+%d minutes', self::DURATION_MINUTES));

        $window = $this->em->wrapInTransaction(function () use ($teacher, $lesson, $lessonId, $token, $now, $expiresAt): CheckInWindow {
            $closed = $this->closeWindows(self::CLOSE_OPEN, $lesson, $now);
            $this->scheduler->complete($teacher, $lessonId);

            $window = new CheckInWindow($lesson, $token->hash, $now, $expiresAt);
            $this->em->persist($window);
            $this->em->flush();

            $this->record($lesson, 'checkin.opened', $window->getId(), [
                'lesson_id' => $lesson->getId()->toRfc4122(),
                'expires_at' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                'closed_windows' => $closed,
            ]);

            return $window;
        });

        return new OpenedCheckIn($window->getId(), $lesson->getId(), $token->plain, $expiresAt);
    }

    /**
     * Closes the lesson's active windows (P3). Returns false, without a write or an audit event,
     * when none is active; an expired window is left as it is. Allowed on an archived course or a
     * cancelled lesson: closing only removes access (P4).
     *
     * @throws CheckInNotFound when the lesson is unknown or another teacher's
     */
    public function close(Teacher $teacher, Uuid $lessonId): bool
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        $now = $this->clock->now();
        $active = $this->windows->findActiveForLesson($lesson, $now);
        if (null === $active) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($lesson, $active, $now): bool {
            if (0 === $this->closeWindows(self::CLOSE_ACTIVE, $lesson, $now)) {
                return false;
            }
            $this->record($lesson, 'checkin.closed', $active->getId(), ['lesson_id' => $lesson->getId()->toRfc4122()]);

            return true;
        });
    }

    /**
     * Checks a public token in the S8 order (research D2). No write.
     */
    public function resolve(string $token): ValidCheckIn|CheckInRefusal
    {
        if (!$this->tokens->isWellFormed($token)) {
            return CheckInRefusal::Malformed;
        }
        $facts = $this->lookup->byTokenHash($this->tokens->hash($token));
        if (null === $facts) {
            return CheckInRefusal::Unknown;
        }
        if (null !== $facts->closedAt) {
            return CheckInRefusal::Closed;
        }
        if ($this->clock->now() >= $facts->expiresAt) {
            return CheckInRefusal::Expired;
        }
        if (LessonStatus::Cancelled === $facts->lessonStatus) {
            return CheckInRefusal::LessonCancelled;
        }
        if ($facts->courseArchived) {
            return CheckInRefusal::CourseArchived;
        }

        return new ValidCheckIn(
            windowId: $facts->windowId,
            lessonId: $facts->lessonId,
            lessonStartsAtUtc: $facts->lessonStartsAtUtc,
            lessonEndsAtUtc: $facts->lessonEndsAtUtc,
            courseId: $facts->courseId,
            courseName: $facts->courseName,
            courseTimezone: $facts->courseTimezone,
            teacherId: $facts->teacherId,
            teacherName: $facts->teacherName,
            expiresAt: $facts->expiresAt,
        );
    }

    private function requireLesson(Teacher $teacher, Uuid $lessonId): LessonSession
    {
        $lesson = $this->lessons->findOwned($teacher, $lessonId);
        if (null === $lesson) {
            throw CheckInNotFound::lesson($lessonId);
        }

        return $lesson;
    }

    private function closeWindows(string $sql, LessonSession $lesson, \DateTimeImmutable $now): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            $sql,
            ['now' => $now, 'lesson' => $lesson->getId()],
            ['now' => Types::DATETIME_IMMUTABLE, 'lesson' => UuidType::NAME],
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(LessonSession $lesson, string $action, Uuid $windowId, array $details): void
    {
        $this->audit->record($lesson->getCourse()->ownerId(), ActorType::Teacher, $action, 'check_in_window', $windowId, $details);
    }
}
