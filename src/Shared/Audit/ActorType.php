<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Who caused an audit event (D6).
 */
enum ActorType: string
{
    case Teacher = 'teacher';
    case Student = 'student';
    case System = 'system';
}
