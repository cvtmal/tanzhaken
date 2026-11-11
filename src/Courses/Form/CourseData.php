<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Course;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the new-course and edit-course form (D1/C3) submits. Lengths follow the `course` columns;
 * the entity checks everything again (InvalidCourse). Messages are keys in the `validators`
 * domain, prefixed with the module (`courses.`, D-038).
 */
final class CourseData
{
    #[Assert\NotBlank(message: 'courses.course.name.blank')]
    #[Assert\Length(max: Course::NAME_MAX, maxMessage: 'courses.course.name.too_long')]
    public ?string $name = null;

    /** ISO weekday, 1 (Monday) to 7 (Sunday). */
    #[Assert\NotNull(message: 'courses.course.weekday.blank')]
    public ?int $weekday = null;

    /** Only `H:i` counts, read in the course's zone. */
    #[Assert\NotNull(message: 'courses.course.local_start_time.blank')]
    public ?\DateTimeImmutable $localStartTime = null;

    #[Assert\NotNull(message: 'courses.course.duration.blank')]
    public ?int $durationMinutes = null;

    #[Assert\Length(max: Course::LOCATION_MAX, maxMessage: 'courses.course.location.too_long')]
    public ?string $location = null;

    /** Local date of the first lesson; only `Y-m-d` counts. */
    #[Assert\NotNull(message: 'courses.course.starts_on.blank')]
    public ?\DateTimeImmutable $startsOn = null;

    /** Local date of the last lesson, optional; only `Y-m-d` counts. */
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsOn', message: 'courses.course.ends_on.before_start')]
    public ?\DateTimeImmutable $endsOn = null;

    public ?string $description = null;

    public static function fromCourse(Course $course): self
    {
        $data = new self();
        $data->name = $course->getName();
        $data->weekday = $course->getWeekday();
        $data->localStartTime = $course->getLocalStartTime();
        $data->durationMinutes = $course->getDurationMinutes();
        $data->location = $course->getLocation();
        $data->startsOn = $course->getStartsOn();
        $data->endsOn = $course->getEndsOn();
        $data->description = $course->getDescription();

        return $data;
    }
}
