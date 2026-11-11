<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Attendance\Entity\AttendanceStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One student at one lesson in a course's CSV export; a null status means "Not marked".
 */
final readonly class CourseRegisterExportRow
{
    public function __construct(
        public Uuid $lessonId,
        public \DateTimeImmutable $startsAtUtc,
        public Uuid $studentId,
        public string $firstName,
        public string $lastName,
        public ?AttendanceStatus $status,
    ) {
    }
}
