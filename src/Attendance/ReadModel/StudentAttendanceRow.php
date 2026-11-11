<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\LessonStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One attendance mark of a student, with its lesson and course (times UTC; print them in
 * courseTimezone).
 */
final readonly class StudentAttendanceRow
{
    public function __construct(
        public Uuid $lessonId,
        public Uuid $courseId,
        public string $courseName,
        public string $courseTimezone,
        public \DateTimeImmutable $startsAtUtc,
        public LessonStatus $lessonStatus,
        public AttendanceStatus $status,
        public AttendanceSource $source,
        public \DateTimeImmutable $markedAt,
    ) {
    }
}
