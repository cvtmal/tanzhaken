<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Course;
use App\Courses\ReadModel\LessonRow;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the add-lesson and re-time forms (CRS-4) submit: a local date and wall-clock time in the
 * course's zone, and an optional length (empty: the course's, or the lesson's current length).
 */
final class LessonData
{
    /** Only `Y-m-d` counts. */
    #[Assert\NotNull(message: 'courses.lesson.date.blank')]
    public ?\DateTimeImmutable $date = null;

    /** Only `H:i` counts. */
    #[Assert\NotNull(message: 'courses.lesson.time.blank')]
    public ?\DateTimeImmutable $time = null;

    #[Assert\Range(min: 1, max: Course::DURATION_MAX, notInRangeMessage: 'courses.lesson.duration.out_of_range')]
    public ?int $durationMinutes = null;

    /**
     * Prefills the re-time form with the lesson's local date, time and length in `$timezone`.
     */
    public static function fromLesson(LessonRow $lesson, string $timezone): self
    {
        $local = $lesson->startsAtUtc->setTimezone(new \DateTimeZone($timezone));
        $data = new self();
        // The form's model zone is UTC (PHP's default): carry the local wall clock, not the instant.
        $data->date = new \DateTimeImmutable($local->format('Y-m-d'));
        $data->time = new \DateTimeImmutable('1970-01-01 '.$local->format('H:i:s'));
        $data->durationMinutes = intdiv($lesson->endsAtUtc->getTimestamp() - $lesson->startsAtUtc->getTimestamp(), 60);

        return $data;
    }

    /**
     * The local start for LessonScheduler::addLesson()/reschedule(): the date's `Y-m-d` with the
     * time's `H:i:s`. Call only on a valid form.
     */
    public function localStart(): \DateTimeImmutable
    {
        if (null === $this->date || null === $this->time) {
            throw new \LogicException('A lesson needs a date and a time.');
        }

        return new \DateTimeImmutable($this->date->format('Y-m-d').' '.$this->time->format('H:i:s'));
    }
}
