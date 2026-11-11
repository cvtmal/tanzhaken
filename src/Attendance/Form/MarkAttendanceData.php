<?php

declare(strict_types=1);

namespace App\Attendance\Form;

use App\Attendance\Service\AttendanceMarker;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What one register row submits (#13, P1): an optional correction reason (audited only), for a
 * change as well as for a clear. The clicked button (present, absent, clear) is the action.
 */
final class MarkAttendanceData
{
    #[Assert\Length(max: AttendanceMarker::REASON_MAX_LENGTH, maxMessage: 'attendance.mark.reason_too_long')]
    public ?string $reason = null;
}
