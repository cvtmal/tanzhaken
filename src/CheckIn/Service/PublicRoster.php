<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * What the public check-in page shows for a valid token: the lesson facts (QR-2) and one label
 * per student who may check in (plan P2, P6).
 */
final readonly class PublicRoster
{
    /**
     * @param list<RosterLabel> $students
     */
    public function __construct(
        public ValidCheckIn $checkIn,
        public array $students,
    ) {
    }
}
