<?php

declare(strict_types=1);

namespace App\Courses\Probe;

use App\Students\Probe\StudentTarget;

/** Courses may use Students: no error expected here. */
final class AllowedStudentUser
{
    public function __construct(public readonly StudentTarget $student)
    {
    }
}
