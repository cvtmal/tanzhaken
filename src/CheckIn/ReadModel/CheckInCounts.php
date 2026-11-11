<?php

declare(strict_types=1);

namespace App\CheckIn\ReadModel;

/**
 * How many of a lesson's checkable students are present, out of how many (counts only, no names: S4).
 */
final readonly class CheckInCounts
{
    public function __construct(
        public int $checkedIn,
        public int $enrolled,
    ) {
    }
}
