<?php

declare(strict_types=1);

namespace App\Attendance\Exception;

final class InvalidAttendance extends \DomainException
{
    public static function studentOfAnotherTeacher(): self
    {
        return new self('The student and the lesson belong to different teachers.');
    }
}
