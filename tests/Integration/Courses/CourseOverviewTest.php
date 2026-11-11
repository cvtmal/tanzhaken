<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Entity\Course;
use App\Courses\ReadModel\CourseCard;
use App\Courses\ReadModel\CourseOverview;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The course list. Today is Tuesday 2026-03-03, 11:00 in Zurich (10:00 UTC).
 */
final class CourseOverviewTest extends KernelTestCase
{
    use BuildsLessonScheduler;
    use ClockSensitiveTrait;

    public function test_it_lists_only_the_teachers_active_courses_by_weekday_time_and_name(): void
    {
        self::boot();
        $teacher = TeacherFactory::createOne();
        $tuesdayB = self::zurichCourse(['teacher' => $teacher, 'name' => 'B Salsa']);
        $tuesdayA = self::zurichCourse(['teacher' => $teacher, 'name' => 'A Bachata']);
        $tuesdayEarly = self::zurichCourse(['teacher' => $teacher, 'name' => 'Z Tango', 'localStartTime' => new \DateTimeImmutable('1970-01-01 09:30:00')]);
        $monday = self::zurichCourse(['teacher' => $teacher, 'name' => 'Zouk', 'weekday' => 1]);
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Archived', 'weekday' => 1], archived: true);
        self::zurichCourse(['name' => 'Someone else', 'weekday' => 1]);

        $cards = self::overview()->forTeacher($teacher);

        self::assertSame(
            [$monday->getId()->toRfc4122(), $tuesdayEarly->getId()->toRfc4122(), $tuesdayA->getId()->toRfc4122(), $tuesdayB->getId()->toRfc4122()],
            array_map(static fn (CourseCard $card): string => $card->id->toRfc4122(), $cards),
        );
        self::assertSame('09:30', $cards[1]->localStartTime);
        self::assertSame('Europe/Zurich', $cards[1]->timezone);
        self::assertSame(60, $cards[1]->durationMinutes);
    }

    public function test_the_student_count_ignores_left_enrollments(): void
    {
        self::boot();
        $course = self::zurichCourse();
        EnrollmentFactory::createMany(2, ['course' => $course]);
        EnrollmentFactory::new()->left()->create(['course' => $course]);

        [$card] = self::overview()->forTeacher($course->getTeacher());

        self::assertSame(2, $card->studentCount);
    }

    public function test_the_next_lesson_skips_past_and_cancelled_lessons(): void
    {
        self::boot();
        $course = self::zurichCourse(['location' => 'Studio 2']);
        $empty = self::zurichCourse(['teacher' => $course->getTeacher(), 'weekday' => 5]);
        self::lesson($course, '2026-03-03 09:00:00');
        LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-03 18:00:00'));
        self::lesson($course, '2026-03-17 18:00:00');
        self::lesson($course, '2026-03-10 18:00:00');

        [$card, $emptyCard] = self::overview()->forTeacher($course->getTeacher());

        self::assertTrue($card->id->equals($course->getId()));
        self::assertSame('2026-03-10 18:00:00', $card->nextLessonAtUtc?->format('Y-m-d H:i:s'));
        self::assertSame('Studio 2', $card->location);
        self::assertSame(0, $card->studentCount);
        self::assertTrue($emptyCard->id->equals($empty->getId()));
        self::assertNull($emptyCard->nextLessonAtUtc);
    }

    public function test_today_and_starts_later_are_read_in_the_course_zone(): void
    {
        self::boot();
        $teacher = TeacherFactory::createOne();
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Running']);
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Later', 'startsOn' => new \DateTimeImmutable('2026-03-10')]);
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Ended', 'startsOn' => new \DateTimeImmutable('2026-01-06'), 'endsOn' => new \DateTimeImmutable('2026-02-24')]);
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Monday', 'weekday' => 1, 'startsOn' => new \DateTimeImmutable('2026-03-02')]);
        // 10:00 UTC on Tuesday is already Wednesday 00:00 on Kiritimati (UTC+14).
        self::zurichCourse(['teacher' => $teacher, 'name' => 'Kiritimati', 'timezone' => 'Pacific/Kiritimati', 'weekday' => 3]);

        $flags = [];
        foreach (self::overview()->forTeacher($teacher) as $card) {
            $flags[$card->name] = [$card->isToday, $card->startsLater];
        }

        self::assertSame(
            ['Monday' => [false, false], 'Ended' => [false, false], 'Later' => [false, true], 'Running' => [true, false], 'Kiritimati' => [true, false]],
            $flags,
        );
    }

    public function test_last_location_is_the_newest_active_course_with_a_location(): void
    {
        self::boot();
        $teacher = TeacherFactory::createOne();
        $overview = self::overview();
        self::assertNull($overview->lastLocation($teacher));

        self::zurichCourse(['teacher' => $teacher, 'location' => 'Studio 1', 'now' => new \DateTimeImmutable('2026-01-01 10:00:00')]);
        self::zurichCourse(['teacher' => $teacher, 'location' => 'Studio 2', 'now' => new \DateTimeImmutable('2026-02-01 10:00:00')]);
        self::zurichCourse(['teacher' => $teacher, 'now' => new \DateTimeImmutable('2026-02-15 10:00:00')]);
        self::zurichCourse(['teacher' => $teacher, 'location' => 'Old hall', 'now' => new \DateTimeImmutable('2026-02-20 10:00:00')], archived: true);
        self::zurichCourse(['location' => 'Elsewhere', 'now' => new \DateTimeImmutable('2026-03-01 10:00:00')]);

        self::assertSame('Studio 2', $overview->lastLocation($teacher));
    }

    private static function boot(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-03 10:00:00');
    }

    private static function overview(): CourseOverview
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new CourseOverview(self::connection(), $clock);
    }

    private static function lesson(Course $course, string $startsAtUtc): void
    {
        LessonSessionFactory::createOne(self::times($course, $startsAtUtc));
    }

    /**
     * @return array{course: Course, startsAtUtc: \DateTimeImmutable, endsAtUtc: \DateTimeImmutable}
     */
    private static function times(Course $course, string $startsAtUtc): array
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return ['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')];
    }
}
