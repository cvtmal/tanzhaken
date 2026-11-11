<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use Symfony\Component\Uid\Uuid;

/**
 * One button of the public roster: the enrollment it confirms and the label shown. No student
 * id and no full last name (plan P2, P6).
 */
final readonly class RosterLabel
{
    public function __construct(
        public Uuid $enrollmentId,
        public string $label,
    ) {
    }
}
