<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use Symfony\Component\Uid\Uuid;

/**
 * A token that passed every S8 check: the facts a public check-in page may show (QR-2: teacher
 * name, course name, lesson time and zone) and the ids the confirm step needs.
 */
final readonly class ValidCheckIn
{
    public function __construct(
        public Uuid $windowId,
        public Uuid $lessonId,
        public \DateTimeImmutable $lessonStartsAtUtc,
        public \DateTimeImmutable $lessonEndsAtUtc,
        public Uuid $courseId,
        public string $courseName,
        public string $courseTimezone,
        public Uuid $teacherId,
        public string $teacherName,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
