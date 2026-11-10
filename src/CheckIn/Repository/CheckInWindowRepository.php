<?php

declare(strict_types=1);

namespace App\CheckIn\Repository;

use App\Account\Entity\Teacher;
use App\CheckIn\Entity\CheckInWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CheckInWindow>
 */
class CheckInWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckInWindow::class);
    }

    /**
     * The teacher's check-in window with this id; null when it does not exist or belongs to another teacher.
     */
    public function findOwned(Teacher $teacher, Uuid $id): ?CheckInWindow
    {
        $window = $this->createQueryBuilder('w')
            ->innerJoin('w.lessonSession', 'l')
            ->innerJoin('l.course', 'c')
            ->andWhere('w.id = :id')
            ->andWhere('c.teacher = :teacher')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $window instanceof CheckInWindow ? $window : null;
    }

    /**
     * The public check-in path: the token is the capability, so no teacher is involved.
     */
    public function findOneByTokenHash(string $tokenHash): ?CheckInWindow
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
