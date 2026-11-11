<?php

declare(strict_types=1);

namespace App\Dashboard\ReadModel;

/**
 * The dashboard's schedule (#17): today's lessons (teacher's zone, chronological), the upcoming
 * ones of the next 7 days, the moment it was read and whether the teacher has any non-archived
 * course. Picks the hero lesson (P4) and decides check-in eligibility (P3) in plain PHP.
 */
final readonly class TodayBoard
{
    /**
     * @param list<DashboardLesson> $today
     * @param list<DashboardLesson> $upcoming
     */
    public function __construct(
        public array $today,
        public array $upcoming,
        public \DateTimeImmutable $now,
        public bool $hasCourses,
    ) {
    }

    /**
     * The latest non-cancelled today lesson within the check-in lead (started, about to start or
     * ended); else the next one to start; null when today has no non-cancelled lesson (P4).
     */
    public function hero(): ?DashboardLesson
    {
        $hero = null;
        $next = null;
        foreach ($this->today as $lesson) {
            if ($lesson->isCancelled()) {
                continue;
            }
            if ($lesson->canOpenCheckIn($this->now)) {
                $hero = $lesson;
            } elseif (null === $next) {
                $next = $lesson;
            }
        }

        return $hero ?? $next;
    }

    /**
     * Today's lessons without the hero, chronological.
     *
     * @return list<DashboardLesson>
     */
    public function others(): array
    {
        $hero = $this->hero();
        if (null === $hero) {
            return $this->today;
        }

        return array_values(array_filter(
            $this->today,
            static fn (DashboardLesson $lesson): bool => !$lesson->lessonId->equals($hero->lessonId),
        ));
    }

    /** Only a lesson of today may get the check-in link; the lesson decides the rest (P3). */
    public function canOpenCheckIn(DashboardLesson $lesson): bool
    {
        foreach ($this->today as $today) {
            if ($today->lessonId->equals($lesson->lessonId)) {
                return $lesson->canOpenCheckIn($this->now);
            }
        }

        return false;
    }

    /**
     * The courses with a non-cancelled lesson today, keyed by RFC 4122 id: "In today's {course}" (P6).
     *
     * @return array<string, true>
     */
    public function todayCourseIds(): array
    {
        $ids = [];
        foreach ($this->today as $lesson) {
            if (!$lesson->isCancelled()) {
                $ids[$lesson->courseId->toRfc4122()] = true;
            }
        }

        return $ids;
    }
}
