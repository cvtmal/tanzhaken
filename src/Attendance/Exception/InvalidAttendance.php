<?php

declare(strict_types=1);

namespace App\Attendance\Exception;

/**
 * A refused attendance change. The messages are for developers; the pages map each
 * case to their own catalogue (#8, #13).
 */
final class InvalidAttendance extends \DomainException
{
    public static function studentOfAnotherTeacher(): self
    {
        return new self('The student and the lesson belong to different teachers.');
    }

    public static function lessonCancelled(): self
    {
        return new self('The lesson is cancelled; attendance cannot be marked.');
    }

    public static function studentNotEnrolled(): self
    {
        return new self('The student is not actively enrolled in the lesson\'s course.');
    }

    public static function notMarked(): self
    {
        return new self('The student has no attendance mark for this lesson.');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self(\sprintf('The reason may have at most %d characters.', $max));
    }

    public static function changedConcurrently(\Throwable $previous): self
    {
        return new self('The attendance of this student changed at the same time; reload and try again.', 0, $previous);
    }

    public static function notEligibleForCheckIn(): self
    {
        return new self('The lesson is unknown or cancelled, or the student is not actively enrolled in its course.');
    }
}
