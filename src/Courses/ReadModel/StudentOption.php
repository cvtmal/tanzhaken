<?php

declare(strict_types=1);

namespace App\Courses\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * A student the teacher can enrol in a course (the enrol picker).
 */
final readonly class StudentOption
{
    public function __construct(
        public Uuid $id,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
