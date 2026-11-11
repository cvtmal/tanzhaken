<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use App\Attendance\Service\CheckInOutcome;

/**
 * The answer to a confirmed check-in (PRD §4.5 (6)): what the scan did, the student's public
 * label and the balance of that enrollment only (`EnrollmentBalance->balance`; it can be
 * negative, #14 words it). No student id, no full last name (plan P6).
 */
final readonly class CheckInConfirmation
{
    public function __construct(
        public CheckInOutcome $outcome,
        public string $label,
        public int $balance,
    ) {
    }
}
