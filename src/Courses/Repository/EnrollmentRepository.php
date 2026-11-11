<?php

declare(strict_types=1);

namespace App\Courses\Repository;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Students\Entity\Student;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Enrollment>
 */
class EnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enrollment::class);
    }

    /**
     * The teacher's enrollment with this id; null when it does not exist or belongs to another teacher.
     */
    public function findOwned(Teacher $teacher, Uuid $id): ?Enrollment
    {
        $enrollment = $this->createQueryBuilder('e')
            ->innerJoin('e.course', 'c')
            ->andWhere('e.id = :id')
            ->andWhere('c.teacher = :teacher')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('teacher', $teacher->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $enrollment instanceof Enrollment ? $enrollment : null;
    }

    /**
     * The enrollment of this student in this course, active or left; null when there is none
     * (unique `(course_id, student_id)`: a left enrollment is rejoined, never re-inserted).
     */
    public function findOneByCourseAndStudent(Course $course, Student $student): ?Enrollment
    {
        return $this->findOneBy(['course' => $course, 'student' => $student]);
    }

    /**
     * Whether an active enrollment of the course other than `$except` has this check-in label,
     * ignoring case and surrounding spaces (D6). Compared with mb_strtolower() in PHP, because
     * SQLite's LOWER() folds ASCII letters only.
     */
    public function hasActiveLabel(Course $course, string $label, ?Enrollment $except = null): bool
    {
        $wanted = mb_strtolower(trim($label));
        if ('' === $wanted) {
            return false;
        }

        $qb = $this->createQueryBuilder('e')
            ->select('e.publicCheckinLabel')
            ->andWhere('e.course = :course')
            ->andWhere('e.leftAt IS NULL')
            ->andWhere('e.publicCheckinLabel IS NOT NULL')
            ->setParameter('course', $course->getId(), UuidType::NAME);
        if (null !== $except) {
            $qb->andWhere('e.id <> :except')->setParameter('except', $except->getId(), UuidType::NAME);
        }

        foreach ($qb->getQuery()->getSingleColumnResult() as $taken) {
            if (mb_strtolower((string) $taken) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
