<?php

declare(strict_types=1);

namespace App\Students\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One of a student's enrollments as the student page shows it (read-only; enrolling is #10's).
 * `weekday` is ISO 1–7; `localStartTime` carries only the time of day, in `timezone`.
 */
final readonly class StudentCourseRow
{
    public function __construct(
        public Uuid $courseId,
        public string $name,
        public int $weekday,
        public \DateTimeImmutable $localStartTime,
        public string $timezone,
        public ?string $location,
        public bool $courseArchived,
        public \DateTimeImmutable $joinedAt,
        public ?\DateTimeImmutable $leftAt,
    ) {
    }

    /** Still on the roster of a course that is not archived. */
    public function isActive(): bool
    {
        return null === $this->leftAt && !$this->courseArchived;
    }
}
