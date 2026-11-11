<?php

declare(strict_types=1);

namespace App\Tests\Integration\Students;

use App\Students\ReadModel\StudentCourseRow;
use App\Students\ReadModel\StudentCourses;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentCoursesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-14 09:00:00');
    }

    public function test_active_enrollments_come_first_then_left_ones_and_archived_courses(): void
    {
        $student = StudentFactory::createOne();
        $teacher = $student->getTeacher();
        $left = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Left class', 'weekday' => 1]);
        $archived = CourseFactory::new()->archived()->create(['teacher' => $teacher, 'name' => 'Old class', 'weekday' => 2]);
        $tuesday = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Tango', 'weekday' => 2]);
        $monday = CourseFactory::createOne([
            'teacher' => $teacher,
            'name' => 'Salsa',
            'weekday' => 1,
            'localStartTime' => new \DateTimeImmutable('1970-01-01 18:30:00'),
            'timezone' => 'Europe/Berlin',
            'location' => 'Studio 2',
        ]);
        EnrollmentFactory::new()->left()->create(['student' => $student, 'course' => $left]);
        EnrollmentFactory::createOne(['student' => $student, 'course' => $archived]);
        EnrollmentFactory::createOne(['student' => $student, 'course' => $tuesday]);
        EnrollmentFactory::createOne(['student' => $student, 'course' => $monday]);
        EnrollmentFactory::createOne(['course' => $monday]);

        $rows = (new StudentCourses(self::connection()))->forStudent($teacher, $student->getId());

        self::assertSame(['Salsa', 'Tango', 'Left class', 'Old class'], array_map(static fn (StudentCourseRow $row): string => $row->name, $rows));
        self::assertSame([true, true, false, false], array_map(static fn (StudentCourseRow $row): bool => $row->isActive(), $rows));

        [$salsa, , $leftRow, $oldRow] = $rows;
        self::assertTrue($salsa->courseId->equals($monday->getId()));
        self::assertSame(1, $salsa->weekday);
        self::assertSame('18:30', $salsa->localStartTime->format('H:i'));
        self::assertSame('Europe/Berlin', $salsa->timezone);
        self::assertSame('Studio 2', $salsa->location);
        self::assertSame('2026-03-14 09:00:00', $salsa->joinedAt->format('Y-m-d H:i:s'));
        self::assertNull($salsa->leftAt);
        self::assertFalse($salsa->courseArchived);

        self::assertNotNull($leftRow->leftAt);
        self::assertFalse($leftRow->courseArchived);
        self::assertTrue($oldRow->courseArchived);
        self::assertNull($oldRow->leftAt);
    }

    public function test_another_teachers_or_unknown_student_gives_nothing(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $student = $enrollment->getStudent();
        $courses = new StudentCourses(self::connection());

        self::assertCount(1, $courses->forStudent($student->getTeacher(), $student->getId()));
        self::assertSame([], $courses->forStudent(TeacherFactory::createOne(), $student->getId()));
        self::assertSame([], $courses->forStudent($student->getTeacher(), Uuid::v7()));
    }
}
