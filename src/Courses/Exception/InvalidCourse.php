<?php

declare(strict_types=1);

namespace App\Courses\Exception;

/**
 * A refused course, enrollment or lesson change. The message is for developers; pages show the
 * translation key from the `courses` catalogue (`course.error.*`) with its parameters.
 */
final class InvalidCourse extends \DomainException
{
    /**
     * @param array<string, scalar> $translationParameters
     */
    private function __construct(
        string $message,
        private readonly string $translationKey,
        private readonly array $translationParameters = [],
    ) {
        parent::__construct($message);
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, scalar>
     */
    public function translationParameters(): array
    {
        return $this->translationParameters;
    }

    public static function emptyName(): self
    {
        return new self('The course name must not be empty.', 'course.error.empty_name');
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self(
            \sprintf('The course %s must not be longer than %d characters.', $field, $max),
            'course.error.too_long',
            ['field' => $field, 'max' => $max],
        );
    }

    public static function invalidTimezone(string $timezone): self
    {
        return new self(\sprintf('"%s" is not a valid time zone.', $timezone), 'course.error.invalid_timezone', ['timezone' => $timezone]);
    }

    public static function invalidWeekday(int $weekday): self
    {
        return new self(
            \sprintf('The weekday must be between 1 (Monday) and 7 (Sunday), %d given.', $weekday),
            'course.error.invalid_weekday',
            ['weekday' => $weekday],
        );
    }

    public static function invalidDuration(int $minutes): self
    {
        return new self(
            \sprintf('The lesson duration must be between 1 and 1440 minutes, %d given.', $minutes),
            'course.error.invalid_duration',
            ['minutes' => $minutes],
        );
    }

    public static function endsBeforeStart(): self
    {
        return new self('The course cannot end before it starts.', 'course.error.ends_before_start');
    }

    public static function alreadyArchived(): self
    {
        return new self('The course is already archived.', 'course.error.already_archived');
    }

    public static function studentOfAnotherTeacher(): self
    {
        return new self('The student and the course belong to different teachers.', 'course.error.student_of_another_teacher');
    }

    public static function enrollmentAlreadyLeft(): self
    {
        return new self('The student has already left the course.', 'course.error.enrollment_already_left');
    }

    public static function lessonEndsBeforeStart(): self
    {
        return new self('The lesson must end after it starts.', 'course.error.lesson_ends_before_start');
    }

    public static function lessonAlreadyCancelled(): self
    {
        return new self('The lesson is already cancelled.', 'course.error.lesson_already_cancelled');
    }

    public static function cancelledLessonCannotComplete(): self
    {
        return new self('A cancelled lesson cannot be completed.', 'course.error.cancelled_lesson_cannot_complete');
    }

    public static function courseArchived(): self
    {
        return new self('The course is archived and its schedule cannot be changed.', 'course.error.course_archived');
    }

    public static function lessonTimeTaken(): self
    {
        return new self('The course already has a lesson starting at that time.', 'course.error.lesson_time_taken');
    }

    public static function lessonHasAttendance(): self
    {
        return new self('The lesson has attendance and cannot be cancelled.', 'course.error.lesson_has_attendance');
    }

    public static function lessonNotCancelled(): self
    {
        return new self('The lesson is not cancelled.', 'course.error.lesson_not_cancelled');
    }

    public static function cancelledLessonCannotMove(): self
    {
        return new self('A cancelled lesson cannot be rescheduled.', 'course.error.cancelled_lesson_cannot_move');
    }

    public static function invalidWeeks(int $weeks): self
    {
        return new self(
            \sprintf('The number of weeks must be between 1 and 104, %d given.', $weeks),
            'course.error.invalid_weeks',
            ['weeks' => $weeks],
        );
    }

    public static function extensionTooFar(int $maxWeeks): self
    {
        return new self(
            \sprintf('The schedule cannot be extended by more than %d weeks at once.', $maxWeeks),
            'course.error.extension_too_far',
            ['max' => $maxWeeks],
        );
    }

    public static function studentArchived(): self
    {
        return new self('The student is archived and cannot be enrolled.', 'course.error.student_archived');
    }

    public static function alreadyEnrolled(): self
    {
        return new self('The student is already enrolled in the course.', 'course.error.already_enrolled');
    }

    public static function labelTaken(string $label): self
    {
        return new self(
            \sprintf('Another student of the course already has the check-in label "%s".', $label),
            'course.error.label_taken',
            ['label' => $label],
        );
    }
}
