<?php

declare(strict_types=1);

namespace App\Courses\Service;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Enrollment;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\EnrollmentRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use App\Students\Repository\StudentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A course's roster (STU-3): enrol an existing student, remove them, set their check-in label.
 * Removing keeps the enrollment row (left_at) and so the attendance and credit history; enrolling
 * a student who left rejoins the same row (unique `(course_id, student_id)`). A check-in label is
 * unique among the course's active enrollments, ignoring case (D6). Archived students cannot be
 * enrolled (D-035).
 *
 * Every refusal (NotFound → 404, InvalidCourse → form error) is thrown before the transaction
 * opens (S2).
 */
final class Roster
{
    private const ENTITY_TYPE = 'enrollment';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly StudentRepository $students,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Enrols one of the teacher's students in one of their courses, or rejoins a left enrollment.
     *
     * @throws NotFound      the course or the student is unknown or another teacher's
     * @throws InvalidCourse archived course or student, already enrolled, label taken or too long
     */
    public function enroll(Teacher $teacher, Uuid $courseId, Uuid $studentId, ?string $label = null): Enrollment
    {
        $course = $this->courses->findOwned($teacher, $courseId) ?? throw NotFound::course($courseId);
        if ($course->isArchived()) {
            throw InvalidCourse::courseArchived();
        }
        $student = $this->students->findOwned($teacher, $studentId) ?? throw NotFound::student($studentId);
        if ($student->isArchived()) {
            throw InvalidCourse::studentArchived();
        }
        $existing = $this->enrollments->findOneByCourseAndStudent($course, $student);
        if (null !== $existing && $existing->isActive()) {
            throw InvalidCourse::alreadyEnrolled();
        }
        $label = self::cleanLabel($label);
        if (null !== $label && $this->enrollments->hasActiveLabel($course, $label)) {
            throw InvalidCourse::labelTaken($label);
        }

        if (null !== $existing) {
            // The entity checks the length and changes nothing when it refuses.
            $existing->changeLabel($label);
            $this->em->wrapInTransaction(function () use ($teacher, $existing): void {
                $existing->rejoin();
                $this->record($teacher, $existing, 'enrollment.rejoined');
            });

            return $existing;
        }

        $enrollment = new Enrollment($course, $student, $this->clock->now(), $label);
        $this->em->wrapInTransaction(function () use ($teacher, $enrollment): void {
            $this->em->persist($enrollment);
            $this->record($teacher, $enrollment, 'enrollment.created');
        });

        return $enrollment;
    }

    /**
     * Takes a student off the course's roster; the enrollment, its attendance and its credit
     * grants stay.
     *
     * @throws NotFound      the enrollment is unknown or another teacher's
     * @throws InvalidCourse archived course, the student has already left
     */
    public function remove(Teacher $teacher, Uuid $enrollmentId): void
    {
        $enrollment = $this->activeEnrollment($teacher, $enrollmentId);
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($teacher, $enrollment, $now): void {
            $enrollment->leave($now);
            $this->record($teacher, $enrollment, 'enrollment.left');
        });
    }

    /**
     * Sets or clears a student's check-in label on the course; no change, no audit.
     *
     * @throws NotFound      the enrollment is unknown or another teacher's
     * @throws InvalidCourse archived course, the student has left, label taken or too long
     */
    public function changeLabel(Teacher $teacher, Uuid $enrollmentId, ?string $label): void
    {
        $enrollment = $this->activeEnrollment($teacher, $enrollmentId);
        $label = self::cleanLabel($label);
        $from = $enrollment->getPublicCheckinLabel();
        if ($label === $from) {
            return;
        }
        if (null !== $label && $this->enrollments->hasActiveLabel($enrollment->getCourse(), $label, $enrollment)) {
            throw InvalidCourse::labelTaken($label);
        }
        // The entity checks the length and changes nothing when it refuses.
        $enrollment->changeLabel($label);

        $this->em->wrapInTransaction(function () use ($teacher, $enrollment, $from, $label): void {
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'enrollment.label_changed', self::ENTITY_TYPE, $enrollment->getId(), [
                'from' => $from,
                'to' => $label,
            ]);
        });
    }

    private function activeEnrollment(Teacher $teacher, Uuid $enrollmentId): Enrollment
    {
        $enrollment = $this->enrollments->findOwned($teacher, $enrollmentId) ?? throw NotFound::enrollment($enrollmentId);
        if ($enrollment->getCourse()->isArchived()) {
            throw InvalidCourse::courseArchived();
        }
        if (!$enrollment->isActive()) {
            throw InvalidCourse::enrollmentAlreadyLeft();
        }

        return $enrollment;
    }

    private static function cleanLabel(?string $label): ?string
    {
        $label = trim($label ?? '');

        return '' === $label ? null : $label;
    }

    private function record(Teacher $teacher, Enrollment $enrollment, string $action): void
    {
        $details = [
            'course_id' => $enrollment->getCourse()->getId()->toRfc4122(),
            'student_id' => $enrollment->getStudent()->getId()->toRfc4122(),
        ];
        if (null !== $enrollment->getPublicCheckinLabel()) {
            $details['label'] = $enrollment->getPublicCheckinLabel();
        }

        $this->audit->record($teacher->getId(), ActorType::Teacher, $action, self::ENTITY_TYPE, $enrollment->getId(), $details);
    }
}
