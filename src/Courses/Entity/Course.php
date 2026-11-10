<?php

declare(strict_types=1);

namespace App\Courses\Entity;

use App\Account\Entity\Teacher;
use App\Courses\Exception\InvalidCourse;
use App\Shared\Security\TeacherOwned;
use App\Shared\Time\Timezones;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A weekly course. Lesson times derive from timezone, weekday (ISO 1 = Monday … 7 = Sunday),
 * local start time and duration; the dates are local calendar dates.
 */
#[ORM\Entity]
#[ORM\Table(name: 'course')]
#[ORM\Index(name: 'idx_course_teacher_archived', columns: ['teacher_id', 'archived_at'])]
class Course implements TeacherOwned
{
    public const NAME_MAX = 120;
    public const LOCATION_MAX = 160;
    public const DURATION_MAX = 1440;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(length: self::LOCATION_MAX, nullable: true)]
    private ?string $location;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $weekday;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $localStartTime;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $durationMinutes;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        Teacher $teacher,
        string $name,
        string $timezone,
        int $weekday,
        \DateTimeImmutable $localStartTime,
        int $durationMinutes,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $endsOn = null,
        ?string $description = null,
        ?string $location = null,
    ) {
        $this->id = Uuid::v7();
        $this->teacher = $teacher;
        $this->createdAt = $now;
        $this->changeDetails($name, $description, $location);
        $this->changeSchedule($timezone, $weekday, $localStartTime, $durationMinutes, $startsOn, $endsOn);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->teacher->getId();
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function getLocalStartTime(): \DateTimeImmutable
    {
        return $this->localStartTime;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function changeDetails(string $name, ?string $description, ?string $location): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw InvalidCourse::emptyName();
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw InvalidCourse::tooLong('name', self::NAME_MAX);
        }
        $location = trim($location ?? '');
        if (mb_strlen($location) > self::LOCATION_MAX) {
            throw InvalidCourse::tooLong('location', self::LOCATION_MAX);
        }
        $description = trim($description ?? '');

        $this->name = $name;
        $this->description = '' === $description ? null : $description;
        $this->location = '' === $location ? null : $location;
    }

    public function changeSchedule(
        string $timezone,
        int $weekday,
        \DateTimeImmutable $localStartTime,
        int $durationMinutes,
        \DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
    ): void {
        if (!Timezones::isValid($timezone)) {
            throw InvalidCourse::invalidTimezone($timezone);
        }
        if ($weekday < 1 || $weekday > 7) {
            throw InvalidCourse::invalidWeekday($weekday);
        }
        if ($durationMinutes < 1 || $durationMinutes > self::DURATION_MAX) {
            throw InvalidCourse::invalidDuration($durationMinutes);
        }
        if (null !== $endsOn && $endsOn->format('Y-m-d') < $startsOn->format('Y-m-d')) {
            throw InvalidCourse::endsBeforeStart();
        }

        $this->timezone = $timezone;
        $this->weekday = $weekday;
        $this->localStartTime = $localStartTime;
        $this->durationMinutes = $durationMinutes;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        if (null !== $this->archivedAt) {
            throw InvalidCourse::alreadyArchived();
        }
        $this->archivedAt = $now;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }
}
