<?php

declare(strict_types=1);

namespace App\Courses\Entity;

use App\Courses\Exception\InvalidCourse;
use App\Shared\Security\TeacherOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One lesson of a course. Start and end are stored in UTC, truncated to whole seconds (P3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'lesson_session')]
#[ORM\UniqueConstraint(name: 'uk_lesson_session_course_starts', columns: ['course_id', 'starts_at_utc'])]
#[ORM\Index(name: 'idx_lesson_session_starts', columns: ['starts_at_utc'])]
class LessonSession implements TeacherOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAtUtc;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAtUtc;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: LessonStatus::class)]
    private LessonStatus $status = LessonStatus::Scheduled;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(Course $course, \DateTimeImmutable $startsAtUtc, \DateTimeImmutable $endsAtUtc)
    {
        $startsAtUtc = self::toUtcSeconds($startsAtUtc);
        $endsAtUtc = self::toUtcSeconds($endsAtUtc);
        if ($endsAtUtc <= $startsAtUtc) {
            throw InvalidCourse::lessonEndsBeforeStart();
        }

        $this->id = Uuid::v7();
        $this->course = $course;
        $this->startsAtUtc = $startsAtUtc;
        $this->endsAtUtc = $endsAtUtc;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->course->ownerId();
    }

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function getStartsAtUtc(): \DateTimeImmutable
    {
        return $this->startsAtUtc;
    }

    public function getEndsAtUtc(): \DateTimeImmutable
    {
        return $this->endsAtUtc;
    }

    public function getStatus(): LessonStatus
    {
        return $this->status;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function complete(): void
    {
        if (LessonStatus::Cancelled === $this->status) {
            throw InvalidCourse::cancelledLessonCannotComplete();
        }
        $this->status = LessonStatus::Completed;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if (LessonStatus::Cancelled === $this->status) {
            throw InvalidCourse::lessonAlreadyCancelled();
        }
        $this->status = LessonStatus::Cancelled;
        $this->cancelledAt = $now;
    }

    public function isCancelled(): bool
    {
        return LessonStatus::Cancelled === $this->status;
    }

    private static function toUtcSeconds(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
