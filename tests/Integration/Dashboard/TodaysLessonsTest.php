<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Dashboard\ReadModel\DashboardLesson;
use App\Dashboard\ReadModel\TodaysLessons;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TodaysLessonsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    /** UTC; a Saturday. */
    private const NOW = '2026-03-14 09:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
    }

    public function test_today_and_upcoming_split_in_the_teachers_zone(): void
    {
        // 23:30 on 14 March in New York (EDT, UTC-4) is 03:30 UTC on 15 March.
        self::mockTime('2026-03-15 03:30:00');
        $teacher = TeacherFactory::createOne(['timezone' => 'America/New_York']);
        $course = CourseFactory::createOne([
            'teacher' => $teacher,
            'name' => 'Salsa',
            'location' => 'Studio 2',
            'timezone' => 'Europe/Berlin',
            'durationMinutes' => 75,
        ]);
        self::lessonAt($course, '2026-03-14 03:00:00'); // 23:00 on 13 March local: yesterday
        $morning = self::lessonAt($course, '2026-03-14 13:00:00'); // 09:00 local
        $night = self::lessonAt($course, '2026-03-15 03:15:00'); // 23:15 local
        $afterMidnight = self::lessonAt($course, '2026-03-15 04:30:00'); // 00:30 on 15 March local

        $board = self::todaysLessons()->forTeacher($teacher);

        self::assertSame([$morning->getId()->toRfc4122(), $night->getId()->toRfc4122()], self::ids($board->today));
        self::assertSame([$afterMidnight->getId()->toRfc4122()], self::ids($board->upcoming));
        self::assertTrue($board->hasCourses);
        self::assertEquals(new \DateTimeImmutable('2026-03-15 03:30:00'), $board->now);

        $lesson = $board->today[0];
        self::assertTrue($lesson->courseId->equals($course->getId()));
        self::assertSame('Salsa', $lesson->courseName);
        self::assertSame('Studio 2', $lesson->location);
        self::assertSame('Europe/Berlin', $lesson->timezone);
        self::assertSame(75, $lesson->durationMinutes);
        self::assertSame('2026-03-14 13:00:00', $lesson->startsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-14 14:00:00', $lesson->endsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $lesson->startsAtUtc->getTimezone()->getName());
        self::assertSame(LessonStatus::Scheduled, $lesson->status);
        self::assertSame(0, $lesson->studentCount);
        self::assertFalse($lesson->checkInOpen);
    }

    public function test_upcoming_ends_seven_days_after_today_and_is_limited(): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $earlyToday = self::lessonAt($course, '2026-03-14 00:00:00');
        $lastDay = self::lessonAt($course, '2026-03-21 23:59:00');
        self::lessonAt($course, '2026-03-22 00:00:00'); // start of today + 8 days: excluded
        self::lessonAt($course, '2026-03-13 23:59:00'); // yesterday: excluded

        $board = self::todaysLessons()->forTeacher($teacher);
        self::assertSame([$earlyToday->getId()->toRfc4122()], self::ids($board->today));
        self::assertSame([$lastDay->getId()->toRfc4122()], self::ids($board->upcoming));

        $upcoming = [];
        for ($i = 0; $i < TodaysLessons::UPCOMING_LIMIT + 2; ++$i) {
            $upcoming[] = self::lessonAt($course, \sprintf('2026-03-16 %02d:00:00', $i))->getId()->toRfc4122();
        }
        $today = [$earlyToday->getId()->toRfc4122()];
        for ($i = 1; $i <= TodaysLessons::UPCOMING_LIMIT + 1; ++$i) {
            $today[] = self::lessonAt($course, \sprintf('2026-03-14 %02d:00:00', $i))->getId()->toRfc4122();
        }

        $board = self::todaysLessons()->forTeacher($teacher);
        self::assertSame($today, self::ids($board->today), "today's lessons are never cut");
        self::assertSame(\array_slice($upcoming, 0, TodaysLessons::UPCOMING_LIMIT), self::ids($board->upcoming));
    }

    public function test_archived_courses_and_other_teachers_are_excluded_cancelled_lessons_are_kept(): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Tango']);
        $archived = CourseFactory::new()->archived()->create(['teacher' => $teacher]);
        $foreign = CourseFactory::createOne();
        $cancelled = LessonSessionFactory::new()->cancelled()->create([
            'course' => $course,
            'startsAtUtc' => new \DateTimeImmutable('2026-03-14 18:00:00'),
            'endsAtUtc' => new \DateTimeImmutable('2026-03-14 19:00:00'),
        ]);
        $scheduled = self::lessonAt($course, '2026-03-15 18:00:00');
        self::lessonAt($archived, '2026-03-14 19:00:00');
        self::lessonAt($foreign, '2026-03-14 19:00:00');
        self::lessonAt($foreign, '2026-03-15 19:00:00');

        $board = self::todaysLessons()->forTeacher($teacher);

        self::assertSame([$cancelled->getId()->toRfc4122()], self::ids($board->today));
        self::assertSame(LessonStatus::Cancelled, $board->today[0]->status);
        self::assertTrue($board->today[0]->isCancelled());
        self::assertSame([$scheduled->getId()->toRfc4122()], self::ids($board->upcoming));
    }

    public function test_student_count_ignores_left_enrollments_and_archived_students(): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $other = CourseFactory::createOne(['teacher' => $teacher]);
        EnrollmentFactory::createMany(2, ['course' => $course]);
        EnrollmentFactory::new()->left()->create(['course' => $course]);
        EnrollmentFactory::createOne([
            'course' => $course,
            'student' => StudentFactory::new()->archived()->create(['teacher' => $teacher]),
        ]);
        EnrollmentFactory::createMany(3, ['course' => $other]);
        self::lessonAt($course, '2026-03-14 18:00:00');

        $board = self::todaysLessons()->forTeacher($teacher);

        self::assertCount(1, $board->today);
        self::assertSame(2, $board->today[0]->studentCount);
    }

    public function test_check_in_open_only_for_a_window_not_closed_and_not_expired(): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $open = self::lessonAt($course, '2026-03-14 08:30:00');
        $closed = self::lessonAt($course, '2026-03-14 08:40:00');
        $expired = self::lessonAt($course, '2026-03-14 06:00:00');
        $none = self::lessonAt($course, '2026-03-14 10:00:00');
        CheckInWindowFactory::createOne(['lessonSession' => $open]);
        CheckInWindowFactory::new()->closed()->create(['lessonSession' => $closed]);
        CheckInWindowFactory::createOne([
            'lessonSession' => $expired,
            'openedAt' => new \DateTimeImmutable('2026-03-14 06:00:00'),
            'expiresAt' => new \DateTimeImmutable(self::NOW),
        ]);

        $byId = [];
        foreach (self::todaysLessons()->forTeacher($teacher)->today as $lesson) {
            $byId[$lesson->lessonId->toRfc4122()] = $lesson->checkInOpen;
        }

        self::assertSame([
            $expired->getId()->toRfc4122() => false,
            $open->getId()->toRfc4122() => true,
            $closed->getId()->toRfc4122() => false,
            $none->getId()->toRfc4122() => false,
        ], $byId);
    }

    public function test_has_courses_tells_no_course_from_no_lesson(): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        CourseFactory::createOne(); // another teacher's
        CourseFactory::new()->archived()->create(['teacher' => $teacher]);

        $board = self::todaysLessons()->forTeacher($teacher);
        self::assertFalse($board->hasCourses);
        self::assertSame([], $board->today);
        self::assertSame([], $board->upcoming);

        CourseFactory::createOne(['teacher' => $teacher]);
        self::assertTrue(self::todaysLessons()->forTeacher($teacher)->hasCourses);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function boardSizes(): iterable
    {
        yield '3 courses' => [3];
        yield '30 courses' => [30];
    }

    #[DataProvider('boardSizes')]
    public function test_query_count_is_constant(int $size): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        for ($i = 0; $i < $size; ++$i) {
            $course = CourseFactory::createOne(['teacher' => $teacher]);
            EnrollmentFactory::createMany(2, ['course' => $course]);
            $today = self::lessonAt($course, \sprintf('2026-03-14 %02d:%02d:00', 10 + intdiv($i, 60), $i % 60));
            self::lessonAt($course, '2026-03-16 18:00:00');
            CheckInWindowFactory::createOne(['lessonSession' => $today]);
        }
        $holder = self::debugDataHolder();
        $lessons = self::todaysLessons();

        $holder->reset();
        $board = $lessons->forTeacher($teacher);
        self::assertCount($size, $board->today);
        self::assertCount(min($size, TodaysLessons::UPCOMING_LIMIT), $board->upcoming);
        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    #[DataProvider('boardSizes')]
    public function test_query_count_without_lessons_is_two(int $size): void
    {
        $teacher = TeacherFactory::createOne(['timezone' => 'UTC']);
        CourseFactory::createMany($size, ['teacher' => $teacher]);
        $holder = self::debugDataHolder();
        $lessons = self::todaysLessons();

        $holder->reset();
        self::assertTrue($lessons->forTeacher($teacher)->hasCourses);
        self::assertCount(2, $holder->getData()['default'] ?? []);
    }

    private static function todaysLessons(): TodaysLessons
    {
        $service = self::getContainer()->get(TodaysLessons::class);
        self::assertInstanceOf(TodaysLessons::class, $service);

        return $service;
    }

    private static function debugDataHolder(): DebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        return $holder;
    }

    /** A 60-minute scheduled lesson of $course starting at $startsAtUtc. */
    private static function lessonAt(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return LessonSessionFactory::createOne([
            'course' => $course,
            'startsAtUtc' => $startsAt,
            'endsAtUtc' => $startsAt->modify('+60 minutes'),
        ]);
    }

    /**
     * @param list<DashboardLesson> $lessons
     *
     * @return list<string>
     */
    private static function ids(array $lessons): array
    {
        return array_map(static fn (DashboardLesson $lesson): string => $lesson->lessonId->toRfc4122(), $lessons);
    }
}
