<?php

declare(strict_types=1);

namespace App\Courses\Entity;

use App\Courses\Exception\InvalidCourse;
use App\Shared\Security\TeacherOwned;
use App\Students\Entity\Student;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A student on a course's roster. Leaving keeps the row (left_at); rejoining clears it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'enrollment')]
#[ORM\UniqueConstraint(name: 'uk_enrollment_course_student', columns: ['course_id', 'student_id'])]
#[ORM\Index(name: 'idx_enrollment_student', columns: ['student_id'])]
class Enrollment implements TeacherOwned
{
    public const LABEL_MAX = 40;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Course $course;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Student $student;

    #[ORM\Column(length: self::LABEL_MAX, nullable: true)]
    private ?string $publicCheckinLabel;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(Course $course, Student $student, \DateTimeImmutable $now, ?string $publicCheckinLabel = null)
    {
        if (!$course->ownerId()->equals($student->ownerId())) {
            throw InvalidCourse::studentOfAnotherTeacher();
        }

        $this->id = Uuid::v7();
        $this->course = $course;
        $this->student = $student;
        $this->joinedAt = $now;
        $this->changeLabel($publicCheckinLabel);
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

    public function getStudent(): Student
    {
        return $this->student;
    }

    public function getPublicCheckinLabel(): ?string
    {
        return $this->publicCheckinLabel;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function changeLabel(?string $label): void
    {
        $label = trim($label ?? '');
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw InvalidCourse::tooLong('check-in label', self::LABEL_MAX);
        }

        $this->publicCheckinLabel = '' === $label ? null : $label;
    }

    public function leave(\DateTimeImmutable $now): void
    {
        if (null !== $this->leftAt) {
            throw InvalidCourse::enrollmentAlreadyLeft();
        }
        $this->leftAt = $now;
    }

    public function rejoin(): void
    {
        $this->leftAt = null;
    }

    public function isActive(): bool
    {
        return null === $this->leftAt;
    }
}
