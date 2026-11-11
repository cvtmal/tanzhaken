<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * When the teacher is offered "Open check-in" for a lesson: from LEAD_MINUTES before it starts.
 * UI only — CheckInWindows::open() is not gated until #19 (D-055).
 */
final class CheckInOffer
{
    /**
     * Same value as App\Dashboard\ReadModel\DashboardLesson::CHECKIN_LEAD_MINUTES
     * (src/Dashboard/ReadModel/DashboardLesson.php:18); CheckIn may not depend on Dashboard (S2).
     */
    public const LEAD_MINUTES = 15;

    public function isOfferable(\DateTimeImmutable $startsAtUtc, \DateTimeImmutable $now): bool
    {
        return $now >= $startsAtUtc->modify(\sprintf('-%d minutes', self::LEAD_MINUTES));
    }
}
