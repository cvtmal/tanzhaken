<?php

declare(strict_types=1);

namespace App\Attendance\Twig;

use App\Account\Entity\Teacher;
use App\Attendance\ReadModel\StudentAttendanceHistory;
use App\Attendance\ReadModel\StudentAttendanceRow;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

/**
 * The student page's attendance history for the #13 slot `student/_attendance.html.twig`:
 * {{ student_attendance(app.user, student.id) }}. The student page (#9) has already answered 404
 * for a foreign student; the read model gives [] for one anyway.
 */
final class StudentAttendanceExtension
{
    public function __construct(private readonly StudentAttendanceHistory $history)
    {
    }

    /**
     * @return list<StudentAttendanceRow> newest lesson first
     */
    #[AsTwigFunction('student_attendance')]
    public function forStudent(Teacher $teacher, Uuid $studentId): array
    {
        return $this->history->forStudent($teacher, $studentId);
    }
}
