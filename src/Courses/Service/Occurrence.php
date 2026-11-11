<?php

declare(strict_types=1);

namespace App\Courses\Service;

/**
 * One generated lesson time: UTC start and end, and the local date in the course's timezone.
 */
final readonly class Occurrence
{
    public function __construct(
        public \DateTimeImmutable $startsAtUtc,
        public \DateTimeImmutable $endsAtUtc,
        public string $localDate,
    ) {
    }
}
