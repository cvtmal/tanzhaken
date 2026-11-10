<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Repository\LessonSessionRepository;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class LessonSessionRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_lesson(): void
    {
        self::bootKernel();
        $lesson = LessonSessionFactory::createOne();
        $teacher = $lesson->getCourse()->getTeacher();
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::repository();

        self::assertSame($lesson, $repository->findOwned($teacher, $lesson->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $lesson->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }

    public function test_has_attendance_turns_true_with_the_first_row(): void
    {
        self::bootKernel();
        $lesson = LessonSessionFactory::createOne();
        $otherLesson = LessonSessionFactory::createOne();
        $repository = self::repository();

        self::assertFalse($repository->hasAttendance($lesson));

        AttendanceFactory::createOne(['lessonSession' => $lesson]);

        self::assertTrue($repository->hasAttendance($lesson));
        self::assertFalse($repository->hasAttendance($otherLesson));
    }

    private static function repository(): LessonSessionRepository
    {
        $repository = self::getContainer()->get(LessonSessionRepository::class);
        self::assertInstanceOf(LessonSessionRepository::class, $repository);

        return $repository;
    }
}
