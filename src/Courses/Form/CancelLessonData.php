<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Service\LessonScheduler;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the cancel-lesson form submits: an optional reason (audited, not shown publicly).
 */
final class CancelLessonData
{
    #[Assert\Length(max: LessonScheduler::REASON_MAX, maxMessage: 'courses.lesson.reason.too_long')]
    public ?string $reason = null;
}
