<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\Teacher;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Form\RegistrationData;
use App\Account\Repository\TeacherRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Self-service registration (ACC-1): one teacher row in `Europe/Zurich` and its audit event.
 * A known email is refused before the transaction opens (S2, D-027: ORM 3 closes the
 * EntityManager on any exception inside wrapInTransaction()); a concurrent duplicate that only
 * the unique key catches is reported the same way.
 */
final class Registration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherRepository $teachers,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Expects data the form has validated.
     *
     * @throws EmailAlreadyRegistered a teacher with this email (case and spaces ignored) exists
     */
    public function register(RegistrationData $data): Teacher
    {
        $email = Teacher::normaliseEmail((string) $data->email);
        if (null !== $this->teachers->findOneByEmail($email)) {
            throw EmailAlreadyRegistered::forEmail($email);
        }

        // The constructor needs the hash, so hash by class rather than via
        // UserPasswordHasherInterface::hashPassword($user, ...).
        $hash = $this->hashers->getPasswordHasher(Teacher::class)->hash((string) $data->password);
        $teacher = new Teacher((string) $data->name, $email, $hash, $this->clock->now());

        try {
            $this->em->wrapInTransaction(function () use ($teacher): void {
                $this->em->persist($teacher);
                $this->em->flush();
                $this->audit->record($teacher->getId(), ActorType::Teacher, 'teacher.registered', 'teacher', $teacher->getId());
            });
        } catch (UniqueConstraintViolationException) {
            // Registered concurrently between the check and the insert (uk_teacher_email).
            throw EmailAlreadyRegistered::forEmail($email);
        }

        return $teacher;
    }
}
