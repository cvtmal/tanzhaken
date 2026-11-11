<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Entity\Course;
use App\Courses\Repository\LessonSessionRepository;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
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

    public function test_latest_start_is_null_without_lessons_and_counts_cancelled_ones(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $repository = self::repository();
        $none = $repository->latestStart($course);

        self::assertNull($none);

        LessonSessionFactory::createOne(self::times($course, '2026-12-01 18:00:00'));
        LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-12-08 18:00:00'));
        LessonSessionFactory::createOne(self::times(CourseFactory::createOne(['teacher' => $course->getTeacher()]), '2026-12-15 18:00:00'));

        $latest = $repository->latestStart($course);
        self::assertNotNull($latest);
        self::assertSame('2026-12-08 18:00:00 UTC', $latest->format('Y-m-d H:i:s e'));
    }

    public function test_starts_of_lists_only_the_courses_lessons(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $otherCourse = CourseFactory::createOne(['teacher' => $course->getTeacher()]);
        LessonSessionFactory::createOne(self::times($course, '2026-12-01 18:00:00'));
        LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-12-08 18:00:00'));
        LessonSessionFactory::createOne(self::times($otherCourse, '2026-12-15 18:00:00'));
        $repository = self::repository();

        self::assertSame(
            ['2026-12-01 18:00:00' => true, '2026-12-08 18:00:00' => true],
            self::sortedKeys($repository->startsOf($course)),
        );
        self::assertSame(['2026-12-15 18:00:00' => true], $repository->startsOf($otherCourse));
        self::assertSame([], $repository->startsOf(CourseFactory::createOne()));
    }

    public function test_is_start_taken_sees_cancelled_lessons_and_skips_the_excepted_one(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $otherCourse = CourseFactory::createOne(['teacher' => $course->getTeacher()]);
        $lesson = LessonSessionFactory::createOne(self::times($course, '2026-12-01 18:00:00'));
        $cancelled = LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-12-08 18:00:00'));
        $repository = self::repository();

        self::assertTrue($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-01 18:00:00', new \DateTimeZone('UTC'))));
        self::assertTrue($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-01 19:00:00', new \DateTimeZone('Europe/Zurich'))));
        self::assertTrue($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-08 18:00:00', new \DateTimeZone('UTC'))));
        self::assertFalse($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-01 18:00:00', new \DateTimeZone('UTC')), $lesson));
        self::assertFalse($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-08 18:00:00', new \DateTimeZone('UTC')), $cancelled));
        self::assertTrue($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-08 18:00:00', new \DateTimeZone('UTC')), $lesson));
        self::assertFalse($repository->isStartTaken($course, new \DateTimeImmutable('2026-12-15 18:00:00', new \DateTimeZone('UTC'))));
        self::assertFalse($repository->isStartTaken($otherCourse, new \DateTimeImmutable('2026-12-01 18:00:00', new \DateTimeZone('UTC'))));
    }

    /**
     * @return array{course: Course, startsAtUtc: \DateTimeImmutable, endsAtUtc: \DateTimeImmutable}
     */
    private static function times(Course $course, string $startsAtUtc): array
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc, new \DateTimeZone('UTC'));

        return ['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')];
    }

    /**
     * @param array<string, true> $starts
     *
     * @return array<string, true>
     */
    private static function sortedKeys(array $starts): array
    {
        ksort($starts);

        return $starts;
    }

    private static function repository(): LessonSessionRepository
    {
        $repository = self::getContainer()->get(LessonSessionRepository::class);
        self::assertInstanceOf(LessonSessionRepository::class, $repository);

        return $repository;
    }
}
