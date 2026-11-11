<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One active enrollment on a course's roster. `studentArchived` marks a student archived after
 * enrolling (shown with a badge). Passed as `row` to the `course/_roster_balance.html.twig` slot
 * (#12 joins Balances by `enrollmentId`).
 */
final readonly class RosterRow
{
    public function __construct(
        public Uuid $enrollmentId,
        public Uuid $studentId,
        public string $firstName,
        public string $lastName,
        public ?string $label,
        public bool $studentArchived,
    ) {
    }
}
