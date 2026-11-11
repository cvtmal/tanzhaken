<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use App\Courses\Entity\LessonStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One lesson of a course page (CRS-5): its UTC times, status and attendance counts
 * (`presentCount` of `markedCount` marked students).
 */
final readonly class LessonRow
{
    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $startsAtUtc,
        public \DateTimeImmutable $endsAtUtc,
        public LessonStatus $status,
        public int $presentCount,
        public int $markedCount,
    ) {
    }
}
