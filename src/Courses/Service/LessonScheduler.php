<?php

declare(strict_types=1);

namespace App\Courses\Service;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\LessonSessionRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The lesson lifecycle of a course: the initial schedule, extending it, and adding,
 * re-timing, cancelling, completing and reinstating single lessons.
 *
 * Every refusal is thrown before the transaction (P2), so the EntityManager stays open for a
 * form re-render. Only the race backstops throw inside it: cancel's second attendance check,
 * and the unique key on (course_id, starts_at_utc), which surfaces as
 * InvalidCourse::lessonTimeTaken() with the EntityManager closed.
 *
 * A "local date" argument or return value is a \DateTimeImmutable of which only
 * format('Y-m-d') counts.
 */
final class LessonScheduler
{
    public const DEFAULT_WEEKS = 26;
    public const MAX_WEEKS = 104;
    public const REASON_MAX = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        private readonly LessonSessionRepository $lessons,
        private readonly ScheduleGenerator $generator,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates the first lessons of a new course (D2): `$weeks` weeks from startsOn, or up to
     * endsOn (at most MAX_WEEKS weeks) when the course has one. The course must be managed;
     * ownership is the caller's. Returns the number of lessons created.
     */
    public function generateInitial(Course $course, int $weeks = self::DEFAULT_WEEKS): int
    {
        if ($course->isArchived()) {
            throw InvalidCourse::courseArchived();
        }
        self::assertWeeks($weeks);

        $from = $course->getStartsOn()->format('Y-m-d');
        $endsOn = $course->getEndsOn();
        $until = null !== $endsOn
            ? min($endsOn->format('Y-m-d'), self::plusDays($from, 7 * self::MAX_WEEKS - 1))
            : self::plusDays($from, 7 * $weeks - 1);

        return $this->createAndRecord($course, $from, $until, 'schedule.generated');
    }

    /**
     * The local date an "Extend by `$weeks` weeks" action would extend to (D4); null when the
     * course is archived or there is nothing left to create. Read only.
     */
    public function extensionTarget(Course $course, int $weeks = self::DEFAULT_WEEKS): ?\DateTimeImmutable
    {
        if ($course->isArchived()) {
            return null;
        }
        self::assertWeeks($weeks);

        $from = $this->extensionStart($course);
        $until = self::plusDays($from, 7 * $weeks - 1);
        $endsOn = $course->getEndsOn();
        if (null !== $endsOn) {
            $until = min($until, $endsOn->format('Y-m-d'));
        }

        if ([] === $this->generator->occurrences($course, self::localDate($from), self::localDate($until))) {
            return null;
        }

        return self::localDate($until);
    }

    /**
     * Creates the course's missing lessons after the latest one (or from today) up to the
     * local date `$untilLocalDate`, clipped to endsOn (D3). Returns the number created.
     */
    public function extend(Teacher $teacher, Uuid $courseId, \DateTimeImmutable $untilLocalDate): int
    {
        $course = $this->requireCourse($teacher, $courseId);

        $from = $this->extensionStart($course);
        $until = $untilLocalDate->format('Y-m-d');
        $endsOn = $course->getEndsOn();
        if (null !== $endsOn) {
            $until = min($until, $endsOn->format('Y-m-d'));
        }
        if ($until > self::plusDays($from, 7 * self::MAX_WEEKS - 1)) {
            throw InvalidCourse::extensionTooFar(self::MAX_WEEKS);
        }
        if ($from > $until) {
            return 0;
        }

        return $this->createAndRecord($course, $from, $until, 'schedule.extended');
    }

    /**
     * Adds one lesson at the local wall-clock time `$localStart` (course zone), lasting
     * `$durationMinutes` or the course's duration. No date rule applies (D10).
     */
    public function addLesson(Teacher $teacher, Uuid $courseId, \DateTimeImmutable $localStart, ?int $durationMinutes = null): LessonSession
    {
        $course = $this->requireCourse($teacher, $courseId);
        $duration = $durationMinutes ?? $course->getDurationMinutes();
        self::assertDuration($duration);

        $start = $this->generator->utcStart($course->getTimezone(), $localStart);
        if ($this->lessons->isStartTaken($course, $start)) {
            throw InvalidCourse::lessonTimeTaken();
        }
        $lesson = new LessonSession($course, $start, $start->modify('+'.$duration.' minutes'));

        try {
            $this->em->wrapInTransaction(function () use ($course, $lesson): void {
                $this->em->persist($lesson);
                $this->em->flush();
                $this->recordLesson($course, $lesson, 'lesson.added', [
                    'startsAtUtc' => $lesson->getStartsAtUtc()->format(\DATE_ATOM),
                    'endsAtUtc' => $lesson->getEndsAtUtc()->format(\DATE_ATOM),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw InvalidCourse::lessonTimeTaken();
        }

        return $lesson;
    }

    /**
     * Moves one lesson to the local wall-clock time `$localStart` (course zone), lasting
     * `$durationMinutes` or its current length. A completed lesson may be re-timed (P5); the
     * same start and end is a silent no-op.
     */
    public function reschedule(Teacher $teacher, Uuid $lessonId, \DateTimeImmutable $localStart, ?int $durationMinutes = null): void
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        $course = $lesson->getCourse();
        $duration = $durationMinutes
            ?? intdiv($lesson->getEndsAtUtc()->getTimestamp() - $lesson->getStartsAtUtc()->getTimestamp(), 60);
        self::assertDuration($duration);
        if ($lesson->isCancelled()) {
            // The entity refuses this too; checked here so the slot check below cannot leave a moved entity behind.
            throw InvalidCourse::cancelledLessonCannotMove();
        }

        $start = $this->generator->utcStart($course->getTimezone(), $localStart);
        $end = $start->modify('+'.$duration.' minutes');
        $fromStart = $lesson->getStartsAtUtc();
        if ($start->getTimestamp() === $fromStart->getTimestamp()
            && $end->getTimestamp() === $lesson->getEndsAtUtc()->getTimestamp()) {
            return;
        }
        if ($this->lessons->isStartTaken($course, $start, except: $lesson)) {
            throw InvalidCourse::lessonTimeTaken();
        }
        $lesson->reschedule($start, $end);

        try {
            $this->em->wrapInTransaction(function () use ($course, $lesson, $fromStart, $duration): void {
                $this->em->flush();
                $this->recordLesson($course, $lesson, 'lesson.rescheduled', [
                    'fromStartsAtUtc' => $fromStart->format(\DATE_ATOM),
                    'toStartsAtUtc' => $lesson->getStartsAtUtc()->format(\DATE_ATOM),
                    'durationMinutes' => $duration,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw InvalidCourse::lessonTimeTaken();
        }
    }

    /**
     * Cancels one lesson that has no attendance (D8). The reason is trimmed; empty means none.
     */
    public function cancel(Teacher $teacher, Uuid $lessonId, ?string $reason = null): void
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        $course = $lesson->getCourse();
        $reason = trim($reason ?? '');
        $reason = '' === $reason ? null : $reason;
        if (null !== $reason && mb_strlen($reason) > self::REASON_MAX) {
            throw InvalidCourse::tooLong('cancellation reason', self::REASON_MAX);
        }
        if ($this->lessons->hasAttendance($lesson)) {
            throw InvalidCourse::lessonHasAttendance();
        }
        $lesson->cancel($this->clock->now());

        $this->em->wrapInTransaction(function () use ($course, $lesson, $reason): void {
            $this->em->flush();
            // Race backstop (D8): a check-in may have marked attendance since the pre-check; rolls back.
            if ($this->lessons->hasAttendance($lesson)) {
                throw InvalidCourse::lessonHasAttendance();
            }
            $this->recordLesson($course, $lesson, 'lesson.cancelled', ['reason' => $reason]);
        });
    }

    /**
     * Marks one lesson completed; a lesson already completed is left as it is.
     */
    public function complete(Teacher $teacher, Uuid $lessonId): void
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        $course = $lesson->getCourse();
        if (LessonStatus::Completed === $lesson->getStatus()) {
            return;
        }
        $lesson->complete();

        $this->em->wrapInTransaction(function () use ($course, $lesson): void {
            $this->em->flush();
            $this->recordLesson($course, $lesson, 'lesson.completed');
        });
    }

    /**
     * Returns a cancelled lesson to scheduled (D6).
     */
    public function reinstate(Teacher $teacher, Uuid $lessonId): void
    {
        $lesson = $this->requireLesson($teacher, $lessonId);
        $course = $lesson->getCourse();
        $lesson->reinstate();

        $this->em->wrapInTransaction(function () use ($course, $lesson): void {
            $this->em->flush();
            $this->recordLesson($course, $lesson, 'lesson.reinstated');
        });
    }

    /**
     * The first local date an extension may create a lesson on: the latest of startsOn, today
     * (course zone) and the day after the latest lesson's local date.
     */
    private function extensionStart(Course $course): string
    {
        $zone = new \DateTimeZone($course->getTimezone());
        $start = max(
            $course->getStartsOn()->format('Y-m-d'),
            $this->clock->now()->setTimezone($zone)->format('Y-m-d'),
        );
        $latest = $this->lessons->latestStart($course);
        if (null !== $latest) {
            $start = max($start, self::plusDays($latest->setTimezone($zone)->format('Y-m-d'), 1));
        }

        return $start;
    }

    private function requireCourse(Teacher $teacher, Uuid $courseId): Course
    {
        $course = $this->courses->findOwned($teacher, $courseId);
        if (null === $course) {
            throw NotFound::course($courseId);
        }
        if ($course->isArchived()) {
            throw InvalidCourse::courseArchived();
        }

        return $course;
    }

    private function requireLesson(Teacher $teacher, Uuid $lessonId): LessonSession
    {
        $lesson = $this->lessons->findOwned($teacher, $lessonId);
        if (null === $lesson) {
            throw NotFound::lesson($lessonId);
        }
        if ($lesson->getCourse()->isArchived()) {
            throw InvalidCourse::courseArchived();
        }

        return $lesson;
    }

    /**
     * Persists a lesson for each occurrence whose start the course does not have yet (any
     * status, so a cancelled or moved-away slot is not recreated). Returns the number persisted.
     *
     * @param list<Occurrence> $occurrences
     */
    private function createLessons(Course $course, array $occurrences): int
    {
        $taken = $this->lessons->startsOf($course);
        $count = 0;
        foreach ($occurrences as $occurrence) {
            if (isset($taken[$occurrence->startsAtUtc->format('Y-m-d H:i:s')])) {
                continue;
            }
            $this->em->persist(new LessonSession($course, $occurrence->startsAtUtc, $occurrence->endsAtUtc));
            ++$count;
        }

        return $count;
    }

    /**
     * Creates the missing lessons between the two local dates and audits `$action` when any
     * were created.
     */
    private function createAndRecord(Course $course, string $from, string $until, string $action): int
    {
        $occurrences = $this->generator->occurrences($course, self::localDate($from), self::localDate($until));

        try {
            return $this->em->wrapInTransaction(function () use ($course, $occurrences, $from, $until, $action): int {
                $count = $this->createLessons($course, $occurrences);
                if (0 === $count) {
                    return 0;
                }
                $this->em->flush();
                $this->audit->record($course->ownerId(), ActorType::Teacher, $action, 'course', $course->getId(), [
                    'from' => $from,
                    'until' => $until,
                    'count' => $count,
                ]);

                return $count;
            });
        } catch (UniqueConstraintViolationException) {
            throw InvalidCourse::lessonTimeTaken();
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function recordLesson(Course $course, LessonSession $lesson, string $action, array $details = []): void
    {
        $this->audit->record($course->ownerId(), ActorType::Teacher, $action, 'lesson_session', $lesson->getId(), $details);
    }

    private static function assertWeeks(int $weeks): void
    {
        if ($weeks < 1 || $weeks > self::MAX_WEEKS) {
            throw InvalidCourse::invalidWeeks($weeks);
        }
    }

    private static function assertDuration(int $minutes): void
    {
        if ($minutes < 1 || $minutes > Course::DURATION_MAX) {
            throw InvalidCourse::invalidDuration($minutes);
        }
    }

    /**
     * A `Y-m-d` date moved by whole calendar days on a UTC midnight value.
     */
    private static function plusDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    private static function localDate(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
