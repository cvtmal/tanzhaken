<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\ReadModel\EnrollableStudents;
use App\Courses\ReadModel\StudentOption;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The enrol picker: the teacher's students who are not archived and not actively enrolled.
 */
final class EnrollableStudentsTest extends KernelTestCase
{
    use BuildsLessonScheduler;

    public function test_it_offers_active_students_not_enrolled_including_one_who_left(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $sam = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Sam', 'lastName' => 'Berg']);
        $alex = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Alex', 'lastName' => 'Berg']);
        $left = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Lea', 'lastName' => 'Aebi']);
        EnrollmentFactory::new()->left()->create(['course' => $course, 'student' => $left]);
        EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::createOne(['teacher' => $teacher])]);
        StudentFactory::new()->archived()->create(['teacher' => $teacher, 'lastName' => 'Aaron']);
        StudentFactory::createOne(['lastName' => 'Aaa']);
        // Enrolled in another course of the teacher: still offered.
        EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher]), 'student' => $sam]);

        $options = (new EnrollableStudents(self::connection()))->forCourse($teacher, $course->getId());

        self::assertSame(
            [$left->getId()->toRfc4122(), $alex->getId()->toRfc4122(), $sam->getId()->toRfc4122()],
            array_map(static fn (StudentOption $option): string => $option->id->toRfc4122(), $options),
        );
        self::assertSame(['Alex', 'Berg'], [$options[1]->firstName, $options[1]->lastName]);
    }

    public function test_another_teachers_course_offers_nobody(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $teacher = TeacherFactory::createOne();
        StudentFactory::createOne(['teacher' => $teacher]);

        self::assertSame([], (new EnrollableStudents(self::connection()))->forCourse($teacher, $course->getId()));
    }
}
