<?php

declare(strict_types=1);

namespace App\Credits\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * The balance of one enrollment, as computed by Balances (the only implementation of the formula).
 * `credits` = lessons of non-voided grants, `used` = `present` attendances at non-cancelled lessons
 * of the enrollment's course, `balance` = credits − used.
 */
final readonly class EnrollmentBalance
{
    public function __construct(
        public Uuid $enrollmentId,
        public Uuid $courseId,
        public string $courseName,
        public Uuid $studentId,
        public string $studentFirstName,
        public string $studentLastName,
        public bool $active,
        public int $credits,
        public int $used,
        public int $balance,
        public RenewalState $state,
    ) {
    }

    /**
     * Lessons attended beyond the credits ("N lessons outstanding", PRD §11); 0 when the balance is not negative.
     */
    public function outstanding(): int
    {
        return max(0, -$this->balance);
    }
}
