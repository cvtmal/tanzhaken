<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Creating, editing and archiving a course. Today is Sunday 2026-03-01; the teacher is in Zurich
 * and the courses run on Tuesdays at 19:00 (18:00 UTC in winter), from Tuesday 2026-03-03.
 */
final class CourseCatalogTest extends KernelTestCase
{
    use BuildsCourseServices;
    use ClockSensitiveTrait;

    public function test_create_stores_the_course_in_the_teacher_zone_and_generates_26_weeks(): void
    {
        self::boot();
        $teacher = self::teacher();

        $course = self::create($teacher, location: 'Studio 2');

        self::assertSame('Europe/Zurich', $course->getTimezone());
        self::assertSame(1, self::countRows('course'));
        $starts = self::lessonStarts($course);
        self::assertCount(26, $starts);
        self::assertSame('2026-03-03 18:00:00', $starts[0]);
        self::assertSame([['name' => 'Salsa Beginners']], self::auditDetails('course.created'));
        self::assertSame([['from' => '2026-03-03', 'until' => '2026-08-31', 'count' => 26]], self::auditDetails('schedule.generated'));
    }

    public function test_create_with_an_end_date_generates_lessons_up_to_it(): void
    {
        self::boot();

        $course = self::create(self::teacher(), endsOn: new \DateTimeImmutable('2026-04-21'));

        $starts = self::lessonStarts($course);
        self::assertCount(8, $starts);
        self::assertSame('2026-04-21 17:00:00', $starts[7]);
    }

    public function test_create_refuses_an_invalid_course_before_the_transaction(): void
    {
        self::boot();
        $teacher = self::teacher();

        self::assertRefused(InvalidCourse::emptyName(), static fn () => self::create($teacher, name: '  '));
        self::assertRefused(
            InvalidCourse::endsBeforeStart(),
            static fn () => self::create($teacher, endsOn: new \DateTimeImmutable('2026-03-02')),
        );

        self::assertSame(0, self::countRows('course'));
        self::assertSame(0, self::countRows('lesson_session'));
        self::assertSame([], self::auditDetails('course.created'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_create_inside_an_outer_transaction_commits_course_and_lessons_together(): void
    {
        self::boot();
        $teacher = self::teacher();

        $course = self::entityManager()->wrapInTransaction(static fn (): Course => self::create($teacher));

        self::assertSame(1, self::countRows('course'));
        self::assertCount(26, self::lessonStarts($course));
        self::assertCount(1, self::auditDetails('course.created'));
        self::assertCount(1, self::auditDetails('schedule.generated'));
    }

    public function test_update_changes_the_fields_audits_their_names_and_leaves_the_lessons(): void
    {
        self::boot();
        $teacher = self::teacher();
        $course = self::create($teacher);
        $lessons = self::lessonRows($course);

        self::update($teacher, $course, name: 'Salsa Improvers', weekday: 4, minutes: 90, location: 'Studio 3');

        self::entityManager()->clear();
        $reloaded = self::entityManager()->find(Course::class, $course->getId());
        self::assertInstanceOf(Course::class, $reloaded);
        self::assertSame('Salsa Improvers', $reloaded->getName());
        self::assertSame(4, $reloaded->getWeekday());
        self::assertSame(90, $reloaded->getDurationMinutes());
        self::assertSame('Studio 3', $reloaded->getLocation());
        self::assertSame('Europe/Zurich', $reloaded->getTimezone());
        self::assertSame($lessons, self::lessonRows($course));
        self::assertSame([['changed' => ['name', 'weekday', 'duration_minutes', 'location']]], self::auditDetails('course.updated'));
    }

    public function test_update_without_a_change_audits_nothing(): void
    {
        self::boot();
        $teacher = self::teacher();
        $course = self::create($teacher, location: 'Studio 2');

        self::update($teacher, $course, location: ' Studio 2 ');

        self::assertSame([], self::auditDetails('course.updated'));
    }

    public function test_update_refuses_an_invalid_field_and_keeps_the_course_unchanged(): void
    {
        self::boot();
        $teacher = self::teacher();
        $course = self::create($teacher);

        self::assertRefused(
            InvalidCourse::endsBeforeStart(),
            static fn () => self::update($teacher, $course, name: 'Renamed', endsOn: new \DateTimeImmutable('2026-03-01')),
        );

        self::assertSame('Salsa Beginners', $course->getName());
        self::assertTrue(self::entityManager()->isOpen());
        self::entityManager()->flush();
        self::assertSame('Salsa Beginners', self::connection()->fetchOne('SELECT name FROM course'));
        self::assertSame([], self::auditDetails('course.updated'));
    }

    public function test_update_refuses_another_teachers_course_and_an_archived_course(): void
    {
        self::boot();
        $teacher = self::teacher();
        $course = self::create($teacher);
        $other = self::teacher();

        self::assertRefused(NotFound::course($course->getId()), static fn () => self::update($other, $course, name: 'Mine'));

        self::courseCatalog()->archive($teacher, $course->getId());
        self::assertRefused(InvalidCourse::courseArchived(), static fn () => self::update($teacher, $course, name: 'Renamed'));
        self::assertSame([], self::auditDetails('course.updated'));
    }

    public function test_archive_sets_archived_at_once_and_audits(): void
    {
        self::boot();
        $teacher = self::teacher();
        $course = self::create($teacher);
        $catalog = self::courseCatalog();

        self::assertRefused(NotFound::course($course->getId()), static fn () => $catalog->archive(self::teacher(), $course->getId()));

        $catalog->archive($teacher, $course->getId());

        self::assertSame('2026-03-01 10:00:00', self::connection()->fetchOne('SELECT archived_at FROM course'));
        self::assertSame([[]], self::auditDetails('course.archived'));
        self::assertRefused(InvalidCourse::alreadyArchived(), static fn () => $catalog->archive($teacher, $course->getId()));
        self::assertCount(1, self::auditDetails('course.archived'));
    }

    private static function boot(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
    }

    private static function teacher(): Teacher
    {
        return TeacherFactory::createOne(['timezone' => 'Europe/Zurich']);
    }

    private static function create(
        Teacher $teacher,
        string $name = 'Salsa Beginners',
        ?\DateTimeImmutable $endsOn = null,
        ?string $location = null,
    ): Course {
        return self::courseCatalog()->create(
            $teacher,
            $name,
            2,
            new \DateTimeImmutable('1970-01-01 19:00:00'),
            60,
            new \DateTimeImmutable('2026-03-03'),
            $endsOn,
            $location,
            null,
        );
    }

    private static function update(
        Teacher $teacher,
        Course $course,
        ?string $name = null,
        ?int $weekday = null,
        ?int $minutes = null,
        ?\DateTimeImmutable $endsOn = null,
        ?string $location = null,
    ): void {
        self::courseCatalog()->update(
            $teacher,
            $course->getId(),
            $name ?? $course->getName(),
            $weekday ?? $course->getWeekday(),
            $course->getLocalStartTime(),
            $minutes ?? $course->getDurationMinutes(),
            $course->getStartsOn(),
            $endsOn ?? $course->getEndsOn(),
            $location ?? $course->getLocation(),
            $course->getDescription(),
        );
    }
}
