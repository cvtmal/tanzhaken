<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Repository\EnrollmentRepository;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class EnrollmentRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_enrollment(): void
    {
        self::bootKernel();
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::getContainer()->get(EnrollmentRepository::class);
        self::assertInstanceOf(EnrollmentRepository::class, $repository);

        self::assertSame($enrollment, $repository->findOwned($teacher, $enrollment->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $enrollment->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }
}
