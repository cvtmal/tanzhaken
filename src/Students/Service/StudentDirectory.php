<?php

declare(strict_types=1);

namespace App\Students\Service;

use App\Account\Entity\Teacher;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use App\Students\Entity\Student;
use App\Students\Exception\InvalidStudent;
use App\Students\Exception\StudentNotFound;
use App\Students\Form\StudentData;
use App\Students\Repository\StudentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes to the student directory (STU-1): create, update, archive, restore, each with its audit
 * event. Every refusal (StudentNotFound → 404, InvalidStudent → form error or warning) is thrown
 * before the transaction opens: ORM 3 closes the EntityManager on any exception inside
 * wrapInTransaction(), which would break a form re-render in the same request (S2).
 *
 * Audit details never carry values: email and notes are for the teacher only (PRD §4.2, D7).
 */
final class StudentDirectory
{
    private const ENTITY_TYPE = 'student';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StudentRepository $students,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidStudent empty or too long name, email too long
     */
    public function create(Teacher $teacher, StudentData $data): Student
    {
        $student = new Student(
            $teacher,
            $data->firstName ?? '',
            $data->lastName ?? '',
            $this->clock->now(),
            $data->email,
            $data->notes,
        );

        $this->em->wrapInTransaction(function () use ($teacher, $student): void {
            $this->em->persist($student);
            $this->record($teacher, $student, 'student.created');
        });

        return $student;
    }

    /**
     * @throws StudentNotFound the student is unknown or another teacher's
     * @throws InvalidStudent  empty or too long name, email too long
     */
    public function update(Teacher $teacher, Uuid $studentId, StudentData $data): Student
    {
        $student = $this->owned($teacher, $studentId);
        $before = self::fields($student);
        $student->changeDetails($data->firstName ?? '', $data->lastName ?? '', $data->email, $data->notes);
        $changed = array_keys(array_diff_assoc(self::fields($student), $before));

        $this->em->wrapInTransaction(function () use ($teacher, $student, $changed): void {
            $this->record($teacher, $student, 'student.updated', ['fields' => $changed]);
        });

        return $student;
    }

    /**
     * @throws StudentNotFound the student is unknown or another teacher's
     * @throws InvalidStudent  already archived
     */
    public function archive(Teacher $teacher, Uuid $studentId): Student
    {
        $student = $this->owned($teacher, $studentId);
        if ($student->isArchived()) {
            throw InvalidStudent::alreadyArchived();
        }
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($teacher, $student, $now): void {
            $student->archive($now);
            $this->record($teacher, $student, 'student.archived');
        });

        return $student;
    }

    /**
     * @throws StudentNotFound the student is unknown or another teacher's
     * @throws InvalidStudent  not archived
     */
    public function restore(Teacher $teacher, Uuid $studentId): Student
    {
        $student = $this->owned($teacher, $studentId);
        if (!$student->isArchived()) {
            throw InvalidStudent::notArchived();
        }

        $this->em->wrapInTransaction(function () use ($teacher, $student): void {
            $student->restore();
            $this->record($teacher, $student, 'student.restored');
        });

        return $student;
    }

    private function owned(Teacher $teacher, Uuid $studentId): Student
    {
        return $this->students->findOwned($teacher, $studentId) ?? throw StudentNotFound::student($studentId);
    }

    /**
     * @return array<string, string>
     */
    private static function fields(Student $student): array
    {
        return [
            'first_name' => $student->getFirstName(),
            'last_name' => $student->getLastName(),
            'email' => $student->getEmail() ?? '',
            'notes' => $student->getNotes() ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(Teacher $teacher, Student $student, string $action, array $details = []): void
    {
        $this->audit->record($teacher->getId(), ActorType::Teacher, $action, self::ENTITY_TYPE, $student->getId(), $details);
    }
}
