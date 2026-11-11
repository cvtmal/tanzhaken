<?php

declare(strict_types=1);

namespace App\CheckIn\Twig;

use App\Account\Entity\Teacher;
use App\CheckIn\ReadModel\CheckInProgress;
use App\CheckIn\Service\CheckInOffer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

/**
 * The lesson-page slot `lesson/_checkin_action.html.twig` (#13's contract passes ids as strings):
 * {{ checkin_open_until(app.user, lesson.id) }} — the active window's expiry for this teacher, or null;
 * {{ checkin_offerable(lesson.startsAtUtc) }} — whether "Open check-in" is offered yet (CheckInOffer, UI only).
 */
final class CheckInExtension
{
    public function __construct(
        private readonly CheckInProgress $progress,
        private readonly CheckInOffer $offer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsTwigFunction('checkin_open_until')]
    public function openUntil(Teacher $teacher, string $lessonId): ?\DateTimeImmutable
    {
        return $this->progress->openUntil($teacher->getId(), Uuid::fromString($lessonId), $this->clock->now());
    }

    #[AsTwigFunction('checkin_offerable')]
    public function offerable(\DateTimeImmutable $startsAtUtc): bool
    {
        return $this->offer->isOfferable($startsAtUtc, $this->clock->now());
    }
}
