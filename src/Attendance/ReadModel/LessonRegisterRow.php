<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One student on a lesson's register. A null status is "Not marked"; enrollmentId is null
 * for a marked student who is no longer in the course's roster (#13 joins Balances by it).
 */
final readonly class LessonRegisterRow
{
    public function __construct(
        public Uuid $studentId,
        public ?Uuid $enrollmentId,
        public string $firstName,
        public string $lastName,
        public ?string $publicCheckinLabel,
        public bool $studentArchived,
        public bool $enrollmentActive,
        public ?AttendanceStatus $status,
        public ?AttendanceSource $source,
        public ?\DateTimeImmutable $markedAt,
    ) {
    }
}
