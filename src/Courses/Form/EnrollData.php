<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Enrollment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the enrol form (STU-3) submits: one of the teacher's enrollable students (RFC 4122 UUID)
 * and an optional check-in label.
 */
final class EnrollData
{
    #[Assert\NotBlank(message: 'courses.enroll.student.blank')]
    public ?string $student = null;

    #[Assert\Length(max: Enrollment::LABEL_MAX, maxMessage: 'courses.enroll.label.too_long')]
    public ?string $label = null;
}
