<?php

declare(strict_types=1);

namespace App\Attendance\Exception;

/**
 * A refused attendance change. The message is for developers; pages show the translation key
 * from the `attendance` catalogue (`attendance.error.*`) with its parameters (#13).
 */
final class InvalidAttendance extends \DomainException
{
    /**
     * @param array<string, scalar> $translationParameters
     */
    private function __construct(
        string $message,
        private readonly string $translationKey,
        private readonly array $translationParameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
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

    public static function studentOfAnotherTeacher(): self
    {
        return new self('The student and the lesson belong to different teachers.', 'attendance.error.student_of_another_teacher');
    }

    public static function lessonCancelled(): self
    {
        return new self('The lesson is cancelled; attendance cannot be marked.', 'attendance.error.lesson_cancelled');
    }

    public static function studentNotEnrolled(): self
    {
        return new self('The student is not actively enrolled in the lesson\'s course.', 'attendance.error.student_not_enrolled');
    }

    public static function notMarked(): self
    {
        return new self('The student has no attendance mark for this lesson.', 'attendance.error.not_marked');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self(
            \sprintf('The reason may have at most %d characters.', $max),
            'attendance.error.reason_too_long',
            ['max' => $max],
        );
    }

    public static function changedConcurrently(\Throwable $previous): self
    {
        return new self(
            'The attendance of this student changed at the same time; reload and try again.',
            'attendance.error.changed_concurrently',
            [],
            $previous,
        );
    }

    public static function notEligibleForCheckIn(): self
    {
        return new self(
            'The lesson is unknown or cancelled, or the student is not actively enrolled in its course.',
            'attendance.error.not_eligible_for_check_in',
        );
    }
}
