<?php

declare(strict_types=1);

namespace App\Courses\Exception;

final class InvalidCourse extends \DomainException
{
    public static function emptyName(): self
    {
        return new self('The course name must not be empty.');
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self(\sprintf('The course %s must not be longer than %d characters.', $field, $max));
    }

    public static function invalidTimezone(string $timezone): self
    {
        return new self(\sprintf('"%s" is not a valid time zone.', $timezone));
    }

    public static function invalidWeekday(int $weekday): self
    {
        return new self(\sprintf('The weekday must be between 1 (Monday) and 7 (Sunday), %d given.', $weekday));
    }

    public static function invalidDuration(int $minutes): self
    {
        return new self(\sprintf('The lesson duration must be between 1 and 1440 minutes, %d given.', $minutes));
    }

    public static function endsBeforeStart(): self
    {
        return new self('The course cannot end before it starts.');
    }

    public static function alreadyArchived(): self
    {
        return new self('The course is already archived.');
    }

    public static function studentOfAnotherTeacher(): self
    {
        return new self('The student and the course belong to different teachers.');
    }

    public static function enrollmentAlreadyLeft(): self
    {
        return new self('The student has already left the course.');
    }

    public static function lessonEndsBeforeStart(): self
    {
        return new self('The lesson must end after it starts.');
    }

    public static function lessonAlreadyCancelled(): self
    {
        return new self('The lesson is already cancelled.');
    }

    public static function cancelledLessonCannotComplete(): self
    {
        return new self('A cancelled lesson cannot be completed.');
    }
}
