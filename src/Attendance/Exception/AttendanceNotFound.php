<?php

declare(strict_types=1);

namespace App\Attendance\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * An unknown id or one of another teacher (S4: the controller answers 404).
 */
final class AttendanceNotFound extends \RuntimeException
{
    public static function lesson(Uuid $id): self
    {
        return new self(\sprintf('Lesson "%s" not found.', $id->toRfc4122()));
    }

    public static function student(Uuid $id): self
    {
        return new self(\sprintf('Student "%s" not found.', $id->toRfc4122()));
    }
}
