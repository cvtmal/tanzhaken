<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Entity\LessonSession;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Generating and extending a course's schedule. Today is Sunday 2026-03-01; the courses run
 * on Tuesdays at 19:00 Zurich time (18:00 UTC in winter, 17:00 UTC in summer).
 */
#[ResetDatabase]
final class LessonSchedulerScheduleTest extends KernelTestCase
{
    use BuildsLessonScheduler;
    use ClockSensitiveTrait;

    public function test_generate_initial_creates_26_weekly_lessons_by_default(): void
    {
        self::boot();
        $course = self::zurichCourse();

        self::assertSame(26, self::scheduler()->generateInitial($course));

        $rows = self::lessonRows($course);
        self::assertCount(26, $rows);
        self::assertSame(['scheduled'], array_values(array_unique(array_column($rows, 'status'))));
        self::assertSame('2026-03-03 18:00:00', $rows[0]['startsAtUtc']);
        self::assertSame('2026-03-03 19:00:00', $rows[0]['endsAtUtc']);
        self::assertSame('2026-03-31 17:00:00', $rows[4]['startsAtUtc']);
        self::assertSame('2026-08-25 17:00:00', $rows[25]['startsAtUtc']);
        self::assertSame([['from' => '2026-03-03', 'until' => '2026-08-31', 'count' => 26]], self::auditDetails('schedule.generated'));
    }

    public function test_generate_initial_stops_at_ends_on(): void
    {
        self::boot();
        $course = self::zurichCourse(['endsOn' => new \DateTimeImmutable('2026-03-24')]);

        self::assertSame(4, self::scheduler()->generateInitial($course));

        self::assertSame(
            ['2026-03-03 18:00:00', '2026-03-10 18:00:00', '2026-03-17 18:00:00', '2026-03-24 18:00:00'],
            self::lessonStarts($course),
        );
        self::assertSame([['from' => '2026-03-03', 'until' => '2026-03-24', 'count' => 4]], self::auditDetails('schedule.generated'));
    }

    public function test_generate_initial_caps_a_far_ends_on_at_104_weeks(): void
    {
        self::boot();
        $course = self::zurichCourse(['endsOn' => new \DateTimeImmutable('2030-12-31')]);

        self::assertSame(104, self::scheduler()->generateInitial($course));

        $starts = self::lessonStarts($course);
        self::assertCount(104, $starts);
        self::assertSame('2028-02-22 18:00:00', $starts[103]);
        self::assertSame([['from' => '2026-03-03', 'until' => '2028-02-28', 'count' => 104]], self::auditDetails('schedule.generated'));
    }

    public function test_generate_initial_twice_creates_nothing_the_second_time(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $scheduler = self::scheduler();
        $scheduler->generateInitial($course);

        self::assertSame(0, $scheduler->generateInitial($course));

        self::assertCount(26, self::lessonRows($course));
        self::assertCount(1, self::auditDetails('schedule.generated'));
    }

    public function test_generate_initial_refuses_an_archived_course_and_invalid_weeks(): void
    {
        self::boot();
        $archived = self::zurichCourse(archived: true);
        $course = self::zurichCourse();
        $scheduler = self::scheduler();

        self::assertRefused(InvalidCourse::courseArchived(), static fn () => $scheduler->generateInitial($archived));
        self::assertRefused(InvalidCourse::invalidWeeks(0), static fn () => $scheduler->generateInitial($course, 0));
        self::assertRefused(InvalidCourse::invalidWeeks(105), static fn () => $scheduler->generateInitial($course, 105));

        self::assertSame(0, self::countRows('lesson_session'));
        self::assertSame(0, self::countRows('audit_event'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_extension_target_is_26_weeks_after_the_latest_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $scheduler = self::scheduler();
        $scheduler->generateInitial($course);

        self::assertSame('2027-02-23', $scheduler->extensionTarget($course)?->format('Y-m-d'));
        self::assertSame('2026-09-01', $scheduler->extensionTarget($course, 1)?->format('Y-m-d'));
        self::assertRefused(InvalidCourse::invalidWeeks(105), static fn () => $scheduler->extensionTarget($course, 105));
    }

    public function test_extension_target_is_null_for_an_archived_or_ended_course(): void
    {
        self::boot();
        $scheduler = self::scheduler();
        $archived = self::zurichCourse(archived: true);
        $endedInThePast = self::zurichCourse([
            'startsOn' => new \DateTimeImmutable('2026-01-06'),
            'endsOn' => new \DateTimeImmutable('2026-02-24'),
        ]);
        $fullyGenerated = self::zurichCourse(['endsOn' => new \DateTimeImmutable('2026-03-24')]);
        $scheduler->generateInitial($fullyGenerated);

        self::assertNull($scheduler->extensionTarget($archived));
        self::assertNull($scheduler->extensionTarget($endedInThePast));
        self::assertNull($scheduler->extensionTarget($fullyGenerated));
    }

    public function test_extend_twice_to_the_same_target_creates_nothing_new(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $teacher = $course->getTeacher();
        $scheduler = self::scheduler();
        $scheduler->generateInitial($course);
        $target = $scheduler->extensionTarget($course);
        self::assertNotNull($target);

        self::assertSame(26, $scheduler->extend($teacher, $course->getId(), $target));
        self::assertSame(0, $scheduler->extend($teacher, $course->getId(), $target));

        $starts = self::lessonStarts($course);
        self::assertCount(52, $starts);
        self::assertSame('2027-02-23 18:00:00', $starts[51]);
        self::assertSame([['from' => '2026-08-26', 'until' => '2027-02-23', 'count' => 26]], self::auditDetails('schedule.extended'));
    }

    public function test_extend_does_not_recreate_the_old_slot_of_a_rescheduled_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $teacher = $course->getTeacher();
        $scheduler = self::scheduler();
        $scheduler->generateInitial($course);
        $last = self::entityManager()->getRepository(LessonSession::class)->findOneBy(['course' => $course], ['startsAtUtc' => 'DESC']);
        self::assertInstanceOf(LessonSession::class, $last);
        $scheduler->reschedule($teacher, $last->getId(), new \DateTimeImmutable('2026-08-26 19:00:00'));
        $target = $scheduler->extensionTarget($course);
        self::assertNotNull($target);

        self::assertSame(26, $scheduler->extend($teacher, $course->getId(), $target));

        $starts = self::lessonStarts($course);
        self::assertCount(52, $starts);
        self::assertNotContains('2026-08-25 17:00:00', $starts);
        self::assertSame(['2026-08-18 17:00:00', '2026-08-26 17:00:00', '2026-09-01 17:00:00'], \array_slice($starts, 24, 3));
    }

    public function test_extend_starts_today_for_a_course_that_ran_out_in_the_past(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $teacher = $course->getTeacher();
        $scheduler = self::scheduler();
        $scheduler->generateInitial($course);
        self::mockTime('2026-10-15 10:00:00');
        $target = $scheduler->extensionTarget($course);
        self::assertSame('2027-04-14', $target?->format('Y-m-d'));

        self::assertSame(26, $scheduler->extend($teacher, $course->getId(), $target));

        $starts = self::lessonStarts($course);
        self::assertCount(52, $starts);
        self::assertSame(['2026-08-25 17:00:00', '2026-10-20 17:00:00'], \array_slice($starts, 25, 2));
        self::assertSame('2027-04-13 17:00:00', $starts[51]);
        self::assertSame([['from' => '2026-10-15', 'until' => '2027-04-14', 'count' => 26]], self::auditDetails('schedule.extended'));
    }

    public function test_extend_is_clipped_to_ends_on(): void
    {
        self::boot();
        $course = self::zurichCourse(['endsOn' => new \DateTimeImmutable('2026-05-26')]);

        self::assertSame(13, self::scheduler()->extend($course->getTeacher(), $course->getId(), new \DateTimeImmutable('2026-12-31')));

        $starts = self::lessonStarts($course);
        self::assertCount(13, $starts);
        self::assertSame('2026-05-26 17:00:00', $starts[12]);
        self::assertSame([['from' => '2026-03-03', 'until' => '2026-05-26', 'count' => 13]], self::auditDetails('schedule.extended'));
    }

    public function test_extend_refuses_a_target_beyond_104_weeks(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $teacher = $course->getTeacher();
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::extensionTooFar(104),
            static fn () => $scheduler->extend($teacher, $course->getId(), new \DateTimeImmutable('2028-02-29')),
        );
        self::assertSame(0, self::countRows('lesson_session'));
        self::assertTrue(self::entityManager()->isOpen());

        self::assertSame(104, $scheduler->extend($teacher, $course->getId(), new \DateTimeImmutable('2028-02-28')));
    }

    public function test_extend_answers_not_found_for_another_teachers_course(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $otherTeacher = TeacherFactory::createOne();
        $scheduler = self::scheduler();

        self::assertRefused(
            NotFound::course($course->getId()),
            static fn () => $scheduler->extend($otherTeacher, $course->getId(), new \DateTimeImmutable('2026-12-31')),
        );
        self::assertSame(0, self::countRows('lesson_session'));
    }

    private static function boot(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
    }
}
