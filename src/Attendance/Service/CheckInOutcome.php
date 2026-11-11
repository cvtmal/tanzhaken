<?php

declare(strict_types=1);

namespace App\Attendance\Service;

/**
 * What a student's scan did (S8): a new present row, an existing present row, or a
 * row the teacher marked absent, which a scan never overwrites.
 */
enum CheckInOutcome: string
{
    case Recorded = 'recorded';
    case AlreadyPresent = 'already_present';
    case MarkedByTeacher = 'marked_by_teacher';
}
