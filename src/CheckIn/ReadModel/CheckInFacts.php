<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

use App\Courses\Entity\LessonStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Everything the public check-in needs to judge a token in one row: the window, its lesson,
 * the lesson's course and the course's teacher. Dates are UTC. No email, no notes.
 */
final readonly class CheckInFacts
{
    public function __construct(
        public Uuid $windowId,
        public \DateTimeImmutable $openedAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $closedAt,
        public Uuid $lessonId,
        public LessonStatus $lessonStatus,
        public \DateTimeImmutable $lessonStartsAtUtc,
        public \DateTimeImmutable $lessonEndsAtUtc,
        public Uuid $courseId,
        public string $courseName,
        public string $courseTimezone,
        public bool $courseArchived,
        public Uuid $teacherId,
        public string $teacherName,
    ) {
    }
}
