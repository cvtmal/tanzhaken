<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One active course on the course list (C1). `localStartTime` is 'H:i' in the course's zone;
 * `nextLessonAtUtc` is the start of the first non-cancelled lesson from now on; `isToday` means
 * today (in the course's zone) is the course's weekday within its date range; `startsLater`
 * means the course's first day is after today (P6).
 */
final readonly class CourseCard
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public int $weekday,
        public string $localStartTime,
        public int $durationMinutes,
        public ?string $location,
        public string $timezone,
        public int $studentCount,
        public ?\DateTimeImmutable $nextLessonAtUtc,
        public \DateTimeImmutable $startsOn,
        public bool $isToday,
        public bool $startsLater,
    ) {
    }
}
