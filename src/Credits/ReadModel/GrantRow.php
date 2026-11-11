<?php

declare(strict_types=1);

namespace App\Credits\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One credit grant of a student's credit history (STU-4), voided ones included. Dates are UTC
 * instants; print them in the teacher's zone.
 */
final readonly class GrantRow
{
    public function __construct(
        public Uuid $grantId,
        public Uuid $enrollmentId,
        public int $quantity,
        public \DateTimeImmutable $grantedAt,
        public ?string $note,
        public ?\DateTimeImmutable $voidedAt,
    ) {
    }

    public function isVoided(): bool
    {
        return null !== $this->voidedAt;
    }
}
