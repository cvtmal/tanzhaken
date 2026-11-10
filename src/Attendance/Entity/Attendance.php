<?php

declare(strict_types=1);

namespace App\Attendance\Entity;

use App\Attendance\Exception\InvalidAttendance;
use App\Courses\Entity\LessonSession;
use App\Shared\Security\TeacherOwned;
use App\Students\Entity\Student;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One student's attendance at one lesson. marked_at is the first record, updated_at the last change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attendance')]
#[ORM\UniqueConstraint(name: 'uk_attendance_lesson_student', columns: ['lesson_session_id', 'student_id'])]
#[ORM\Index(name: 'idx_attendance_student_status', columns: ['student_id', 'status'])]
class Attendance implements TeacherOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LessonSession $lessonSession;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Student $student;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AttendanceStatus::class)]
    private AttendanceStatus $status;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AttendanceSource::class)]
    private AttendanceSource $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $markedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        LessonSession $lessonSession,
        Student $student,
        AttendanceStatus $status,
        AttendanceSource $source,
        \DateTimeImmutable $now,
    ) {
        if (!$lessonSession->ownerId()->equals($student->ownerId())) {
            throw InvalidAttendance::studentOfAnotherTeacher();
        }

        $this->id = Uuid::v7();
        $this->lessonSession = $lessonSession;
        $this->student = $student;
        $this->status = $status;
        $this->source = $source;
        $this->markedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->lessonSession->ownerId();
    }

    public function getLessonSession(): LessonSession
    {
        return $this->lessonSession;
    }

    public function getStudent(): Student
    {
        return $this->student;
    }

    public function getStatus(): AttendanceStatus
    {
        return $this->status;
    }

    public function getSource(): AttendanceSource
    {
        return $this->source;
    }

    public function getMarkedAt(): \DateTimeImmutable
    {
        return $this->markedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function change(AttendanceStatus $status, AttendanceSource $source, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->source = $source;
        $this->updatedAt = $now;
    }
}
