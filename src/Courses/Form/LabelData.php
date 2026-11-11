<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Enrollment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the check-in label form of a roster row submits; empty clears the label.
 */
final class LabelData
{
    #[Assert\Length(max: Enrollment::LABEL_MAX, maxMessage: 'courses.enroll.label.too_long')]
    public ?string $label = null;
}
