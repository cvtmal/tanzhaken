<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Repository\CourseRepository;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CourseRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_course(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::getContainer()->get(CourseRepository::class);
        self::assertInstanceOf(CourseRepository::class, $repository);

        self::assertSame($course, $repository->findOwned($teacher, $course->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $course->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }
}
