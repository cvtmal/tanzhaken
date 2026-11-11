<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One student a check-in may be recorded for: an active enrollment of a non-archived student.
 * Raw names for App\CheckIn\Service\RosterLabeller; never rendered as they are (PRD §4.5).
 */
final readonly class RosterEntry
{
    public function __construct(
        public Uuid $enrollmentId,
        public Uuid $studentId,
        public string $firstName,
        public string $lastName,
        public ?string $publicCheckinLabel,
        public \DateTimeImmutable $joinedAt,
    ) {
    }
}
