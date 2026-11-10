<?php

declare(strict_types=1);

namespace App\Courses\Repository;

use App\Account\Entity\Teacher;
use App\Courses\Entity\LessonSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LessonSession>
 */
class LessonSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonSession::class);
    }

    /**
     * The teacher's lesson with this id; null when it does not exist or belongs to another teacher.
     */
    public function findOwned(Teacher $teacher, Uuid $id): ?LessonSession
    {
        $lesson = $this->createQueryBuilder('l')
            ->innerJoin('l.course', 'c')
            ->andWhere('l.id = :id')
            ->andWhere('c.teacher = :teacher')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $lesson instanceof LessonSession ? $lesson : null;
    }

    /**
     * Whether any attendance row exists for the lesson. The attendance table is the
     * Attendance module's, so Courses reads it with its own DBAL query (S2).
     */
    public function hasAttendance(LessonSession $lesson): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM attendance WHERE lesson_session_id = ? LIMIT 1',
            [$lesson->getId()],
            [UuidType::NAME],
        );
    }
}
