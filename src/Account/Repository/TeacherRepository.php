<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidTeacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Teacher>
 */
class TeacherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Teacher::class);
    }

    public function findOneByEmail(string $email): ?Teacher
    {
        try {
            $email = Teacher::normaliseEmail($email);
        } catch (InvalidTeacher) {
            return null;
        }

        return $this->findOneBy(['email' => $email]);
    }
}
