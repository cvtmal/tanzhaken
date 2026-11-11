<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Courses\ReadModel\CourseLessons;
use App\Courses\ReadModel\LessonRow;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The lesson list of a course page. Lessons run on Tuesdays 18:00–19:00 UTC.
 */
final class CourseLessonsTest extends KernelTestCase
{
    use BuildsLessonScheduler;
    use ClockSensitiveTrait;

    public function test_it_lists_every_lesson_of_the_course_in_start_order_with_its_status(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $third = self::lesson($course, '2026-03-17 18:00:00');
        $first = self::lesson($course, '2026-03-03 18:00:00', LessonSessionFactory::new()->completed());
        $second = self::lesson($course, '2026-03-10 18:00:00', LessonSessionFactory::new()->cancelled());
        self::lesson(self::zurichCourse(['teacher' => $course->getTeacher()]), '2026-03-03 18:00:00');

        $rows = self::lessons()->forCourse($course->getTeacher(), $course->getId());

        self::assertSame(
            [$first->getId()->toRfc4122(), $second->getId()->toRfc4122(), $third->getId()->toRfc4122()],
            array_map(static fn (LessonRow $row): string => $row->id->toRfc4122(), $rows),
        );
        self::assertSame([LessonStatus::Completed, LessonStatus::Cancelled, LessonStatus::Scheduled], array_map(static fn (LessonRow $row): LessonStatus => $row->status, $rows));
        self::assertSame('2026-03-03 18:00:00', $rows[0]->startsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-03 19:00:00', $rows[0]->endsAtUtc->format('Y-m-d H:i:s'));
    }

    public function test_another_teachers_course_gives_no_lessons(): void
    {
        self::boot();
        $course = self::zurichCourse();
        self::lesson($course, '2026-03-03 18:00:00');

        self::assertSame([], self::lessons()->forCourse(TeacherFactory::createOne(), $course->getId()));
    }

    public function test_it_counts_marked_and_present_students_per_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $marked = self::lesson($course, '2026-03-03 18:00:00');
        self::lesson($course, '2026-03-10 18:00:00');
        AttendanceFactory::createMany(2, ['lessonSession' => $marked]);
        AttendanceFactory::createOne(['lessonSession' => $marked, 'status' => AttendanceStatus::Absent]);

        $rows = self::lessons()->forCourse($course->getTeacher(), $course->getId());

        self::assertSame([[2, 3], [0, 0]], array_map(static fn (LessonRow $row): array => [$row->presentCount, $row->markedCount], $rows));
    }

    public function test_next_and_previous_skip_cancelled_lessons_and_count_a_running_lesson_as_next(): void
    {
        self::boot();
        $course = self::zurichCourse();
        self::lesson($course, '2026-03-03 18:00:00', LessonSessionFactory::new()->completed());
        self::lesson($course, '2026-03-09 18:00:00', LessonSessionFactory::new()->cancelled());
        self::lesson($course, '2026-03-10 18:00:00');
        self::lesson($course, '2026-03-17 18:00:00', LessonSessionFactory::new()->cancelled());
        self::lesson($course, '2026-03-24 18:00:00');
        $rows = self::lessons()->forCourse($course->getTeacher(), $course->getId());

        $during = new \DateTimeImmutable('2026-03-10 18:30:00');
        self::assertSame('2026-03-10', CourseLessons::next($rows, $during)?->startsAtUtc->format('Y-m-d'));
        self::assertSame('2026-03-03', CourseLessons::previous($rows, $during)?->startsAtUtc->format('Y-m-d'));

        $after = new \DateTimeImmutable('2026-03-10 19:30:00');
        self::assertSame('2026-03-24', CourseLessons::next($rows, $after)?->startsAtUtc->format('Y-m-d'));
        self::assertSame('2026-03-10', CourseLessons::previous($rows, $after)?->startsAtUtc->format('Y-m-d'));

        self::assertNull(CourseLessons::previous($rows, new \DateTimeImmutable('2026-03-01 00:00:00')));
        self::assertNull(CourseLessons::next($rows, new \DateTimeImmutable('2026-04-01 00:00:00')));
        self::assertNull(CourseLessons::next([], $during));
    }

    private static function boot(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
    }

    private static function lessons(): CourseLessons
    {
        return new CourseLessons(self::connection());
    }

    private static function lesson(Course $course, string $startsAtUtc, ?LessonSessionFactory $factory = null): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return ($factory ?? LessonSessionFactory::new())->create([
            'course' => $course,
            'startsAtUtc' => $startsAt,
            'endsAtUtc' => $startsAt->modify('+60 minutes'),
        ]);
    }
}
