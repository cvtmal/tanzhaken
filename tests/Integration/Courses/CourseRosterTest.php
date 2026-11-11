<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\ReadModel\CourseRoster;
use App\Courses\ReadModel\RosterRow;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The roster of a course page: active enrollments by last and first name.
 */
final class CourseRosterTest extends KernelTestCase
{
    use BuildsLessonScheduler;
    use ClockSensitiveTrait;

    public function test_it_lists_the_active_enrollments_by_last_and_first_name(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $sam = EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Sam', 'lastName' => 'Berg'])]);
        $alex = EnrollmentFactory::createOne([
            'course' => $course,
            'student' => StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Alex', 'lastName' => 'Berg']),
            'publicCheckinLabel' => 'Alex B.',
        ]);
        $archived = StudentFactory::new()->archived()->create(['teacher' => $teacher, 'firstName' => 'Mia', 'lastName' => 'Aebi']);
        $mia = EnrollmentFactory::createOne(['course' => $course, 'student' => $archived]);
        EnrollmentFactory::new()->left()->create(['course' => $course, 'student' => StudentFactory::createOne(['teacher' => $teacher, 'lastName' => 'Aaron'])]);
        EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher])]);

        $rows = (new CourseRoster(self::connection()))->forCourse($teacher, $course->getId());

        self::assertSame(
            [$mia->getId()->toRfc4122(), $alex->getId()->toRfc4122(), $sam->getId()->toRfc4122()],
            array_map(static fn (RosterRow $row): string => $row->enrollmentId->toRfc4122(), $rows),
        );
        self::assertTrue($rows[0]->studentArchived);
        self::assertTrue($rows[0]->studentId->equals($archived->getId()));
        self::assertFalse($rows[1]->studentArchived);
        self::assertSame(['Alex', 'Berg', 'Alex B.'], [$rows[1]->firstName, $rows[1]->lastName, $rows[1]->label]);
        self::assertNull($rows[2]->label);
    }

    public function test_another_teachers_course_gives_an_empty_roster(): void
    {
        self::bootKernel();
        $enrollment = EnrollmentFactory::createOne();

        self::assertSame([], (new CourseRoster(self::connection()))->forCourse(TeacherFactory::createOne(), $enrollment->getCourse()->getId()));
    }
}
