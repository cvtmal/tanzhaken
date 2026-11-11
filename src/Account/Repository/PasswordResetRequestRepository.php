<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    public function findOneByTokenHash(string $hash): ?PasswordResetRequest
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Deletes every pending reset link of the teacher (new request, used link, password change).
     */
    public function deleteForTeacher(Teacher $teacher): void
    {
        $this->getEntityManager()
            ->createQuery('DELETE FROM '.PasswordResetRequest::class.' r WHERE r.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->execute();
    }

    /**
     * Deletes every expired reset link of every teacher (data minimisation, F-m2). `<=` matches
     * PasswordResetRequest::isExpiredAt().
     *
     * @return int the number of rows deleted
     */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $deleted = $this->getEntityManager()
            ->createQuery('DELETE FROM '.PasswordResetRequest::class.' r WHERE r.expiresAt <= :now')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
