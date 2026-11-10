<?php

declare(strict_types=1);

namespace App\Tests\Integration\Students;

use App\Students\Repository\StudentRepository;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_student(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::getContainer()->get(StudentRepository::class);
        self::assertInstanceOf(StudentRepository::class, $repository);

        self::assertSame($student, $repository->findOwned($teacher, $student->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $student->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }
}
