<?php

declare(strict_types=1);

namespace App\Attendance\Entity;

/**
 * Who recorded the attendance: the student by QR check-in, or the teacher.
 */
enum AttendanceSource: string
{
    case StudentQr = 'student_qr';
    case Teacher = 'teacher';
}
