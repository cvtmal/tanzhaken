<?php

declare(strict_types=1);

namespace App\Credits\Repository;

use App\Account\Entity\Teacher;
use App\Credits\Entity\CreditGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CreditGrant>
 */
class CreditGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditGrant::class);
    }

    /**
     * The teacher's credit grant with this id; null when it does not exist or belongs to another teacher.
     */
    public function findOwned(Teacher $teacher, Uuid $id): ?CreditGrant
    {
        $grant = $this->createQueryBuilder('g')
            ->innerJoin('g.enrollment', 'e')
            ->innerJoin('e.course', 'c')
            ->andWhere('g.id = :id')
            ->andWhere('c.teacher = :teacher')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $grant instanceof CreditGrant ? $grant : null;
    }
}
