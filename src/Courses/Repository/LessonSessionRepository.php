<?php

declare(strict_types=1);

namespace App\Courses\Repository;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * The start of the course's latest lesson, whatever its status; null when the course has none.
     */
    public function latestStart(Course $course): ?\DateTimeImmutable
    {
        $latest = $this->createQueryBuilder('l')
            ->select('MAX(l.startsAtUtc)')
            ->andWhere('l.course = :course')
            ->setParameter('course', $course->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        if (!\is_string($latest) || '' === $latest) {
            return null;
        }

        return new \DateTimeImmutable($latest, new \DateTimeZone('UTC'));
    }

    /**
     * The starts of every lesson of the course (any status), keyed `Y-m-d H:i:s` in UTC.
     *
     * @return array<string, true>
     */
    public function startsOf(Course $course): array
    {
        /** @var list<array{startsAtUtc: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.startsAtUtc')
            ->andWhere('l.course = :course')
            ->setParameter('course', $course->getId(), UuidType::NAME)
            ->getQuery()
            ->getArrayResult();

        $starts = [];
        foreach ($rows as $row) {
            $starts[$row['startsAtUtc']->format('Y-m-d H:i:s')] = true;
        }

        return $starts;
    }

    /**
     * Whether the course already has a lesson (any status) starting at this instant, other than `$except`.
     */
    public function isStartTaken(Course $course, \DateTimeImmutable $startsAtUtc, ?LessonSession $except = null): bool
    {
        $query = $this->createQueryBuilder('l')
            ->select('1')
            ->andWhere('l.course = :course')
            ->andWhere('l.startsAtUtc = :startsAtUtc')
            ->setParameter('course', $course->getId(), UuidType::NAME)
            ->setParameter('startsAtUtc', $startsAtUtc->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1);

        if (null !== $except) {
            $query->andWhere('l.id != :except')->setParameter('except', $except->getId(), UuidType::NAME);
        }

        return [] !== $query->getQuery()->getScalarResult();
    }
}
