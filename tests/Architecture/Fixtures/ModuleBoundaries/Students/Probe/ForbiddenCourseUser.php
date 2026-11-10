<?php

declare(strict_types=1);

namespace App\Students\Probe;

use App\Courses\Probe\CourseTarget;

/** Students must not use Courses: the probe expects exactly this one error. */
final class ForbiddenCourseUser
{
    public function __construct(public readonly CourseTarget $course)
    {
    }
}
