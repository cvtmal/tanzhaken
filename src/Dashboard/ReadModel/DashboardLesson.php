<?php

declare(strict_types=1);

namespace App\Dashboard\ReadModel;

use App\Courses\Entity\LessonStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One lesson on the dashboard (#17): today's or an upcoming lesson of a non-archived course,
 * with its course's facts, the number of active students and whether a check-in window is open.
 * Times are UTC; the course's `timezone` is the zone to print them in (S7).
 */
final readonly class DashboardLesson
{
    /** A check-in may be opened this many minutes before the lesson starts (P3). */
    public const CHECKIN_LEAD_MINUTES = 15;

    public function __construct(
        public Uuid $lessonId,
        public Uuid $courseId,
        public string $courseName,
        public ?string $location,
        public string $timezone,
        public int $durationMinutes,
        public \DateTimeImmutable $startsAtUtc,
        public \DateTimeImmutable $endsAtUtc,
        public LessonStatus $status,
        public int $studentCount,
        public bool $checkInOpen,
    ) {
    }

    public function isCancelled(): bool
    {
        return LessonStatus::Cancelled === $this->status;
    }

    /**
     * Not cancelled and at most CHECKIN_LEAD_MINUTES before its start (started or ended included).
     * Whether the lesson is today is TodayBoard's call.
     */
    public function canOpenCheckIn(\DateTimeImmutable $now): bool
    {
        return !$this->isCancelled()
            && $this->startsAtUtc->modify(\sprintf('-%d minutes', self::CHECKIN_LEAD_MINUTES)) <= $now;
    }

    /** Whole minutes since the lesson ended; null while it has not ended. */
    public function minutesSinceEnd(\DateTimeImmutable $now): ?int
    {
        if ($now < $this->endsAtUtc) {
            return null;
        }

        return intdiv($now->getTimestamp() - $this->endsAtUtc->getTimestamp(), 60);
    }

    /** Whole minutes, rounded up, until the lesson starts; null once it has started. */
    public function minutesUntilStart(\DateTimeImmutable $now): ?int
    {
        if ($now >= $this->startsAtUtc) {
            return null;
        }

        return (int) ceil(($this->startsAtUtc->getTimestamp() - $now->getTimestamp()) / 60);
    }

    public function isInProgress(\DateTimeImmutable $now): bool
    {
        return $this->startsAtUtc <= $now && $now < $this->endsAtUtc;
    }
}
