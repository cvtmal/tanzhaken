<?php

declare(strict_types=1);

namespace App\Attendance\Entity;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
}
