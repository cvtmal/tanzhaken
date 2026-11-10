<?php

declare(strict_types=1);

namespace App\Attendance\Repository;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\Attendance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Attendance>
 */
class AttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attendance::class);
    }

    /**
     * The teacher's attendance row with this id; null when it does not exist or belongs to another teacher.
     */
    public function findOwned(Teacher $teacher, Uuid $id): ?Attendance
    {
        $attendance = $this->createQueryBuilder('a')
            ->innerJoin('a.lessonSession', 'l')
            ->innerJoin('l.course', 'c')
            ->andWhere('a.id = :id')
            ->andWhere('c.teacher = :teacher')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $attendance instanceof Attendance ? $attendance : null;
    }
}
