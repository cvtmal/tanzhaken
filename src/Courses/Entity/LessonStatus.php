<?php

declare(strict_types=1);

namespace App\Courses\Entity;

/**
 * The lifecycle of one lesson (PRD §4.3).
 */
enum LessonStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
