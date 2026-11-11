<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\ReadModel\LessonRegister;
use App\Attendance\ReadModel\LessonRegisterRow;
use App\Courses\Entity\Course;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class LessonRegisterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    public function test_roster_shows_not_marked_present_and_absent(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:05:00');
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $anna = self::enrolled($course, 'Anna', 'Arnold', 'Anna A.');
        $ben = self::enrolled($course, 'Ben', 'Brun');
        $cleo = self::enrolled($course, 'Cleo', 'Caduff');
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $ben, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::StudentQr]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $cleo, 'status' => AttendanceStatus::Absent, 'source' => AttendanceSource::Teacher]);

        $rows = self::register()->forLesson($course->getTeacher(), $lesson->getId());

        self::assertNotNull($rows);
        self::assertSame(['Arnold', 'Brun', 'Caduff'], self::lastNames($rows));
        self::assertTrue($rows[0]->studentId->equals($anna->getId()));
        self::assertSame('Anna A.', $rows[0]->publicCheckinLabel);
        self::assertSame([null, AttendanceStatus::Present, AttendanceStatus::Absent], array_map(static fn (LessonRegisterRow $row): ?AttendanceStatus => $row->status, $rows));
        self::assertSame([null, AttendanceSource::StudentQr, AttendanceSource::Teacher], array_map(static fn (LessonRegisterRow $row): ?AttendanceSource => $row->source, $rows));
        self::assertNull($rows[0]->markedAt);
        self::assertSame('2026-03-10 20:05:00', $rows[1]->markedAt?->format('Y-m-d H:i:s'));
        foreach ($rows as $row) {
            self::assertNotNull($row->enrollmentId);
            self::assertTrue($row->enrollmentActive);
            self::assertFalse($row->studentArchived);
        }
    }

    public function test_left_student_without_mark_is_excluded_and_with_mark_included(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        EnrollmentFactory::new()->left()->create(['course' => $course, 'student' => StudentFactory::createOne(['teacher' => $course->getTeacher(), 'lastName' => 'Left'])]);
        $marked = EnrollmentFactory::new()->left()->create(['course' => $course, 'student' => StudentFactory::createOne(['teacher' => $course->getTeacher(), 'lastName' => 'Marked'])]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $marked->getStudent()]);

        $rows = self::register()->forLesson($course->getTeacher(), $lesson->getId());

        self::assertNotNull($rows);
        self::assertSame(['Marked'], self::lastNames($rows));
        self::assertFalse($rows[0]->enrollmentActive);
        self::assertTrue($rows[0]->enrollmentId?->equals($marked->getId()));
        self::assertSame(AttendanceStatus::Present, $rows[0]->status);
    }

    public function test_student_of_another_course_is_excluded(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $otherCourse = CourseFactory::createOne(['teacher' => $teacher]);
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        self::enrolled($course, 'In', 'Course');
        self::enrolled($otherCourse, 'Other', 'Course');
        StudentFactory::createOne(['teacher' => $teacher, 'lastName' => 'Nowhere']);

        $rows = self::register()->forLesson($teacher, $lesson->getId());

        self::assertNotNull($rows);
        self::assertCount(1, $rows);
        self::assertSame('In', $rows[0]->firstName);
    }

    public function test_rows_are_ordered_by_last_then_first_name(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        self::enrolled($course, 'Zoe', 'Meier');
        self::enrolled($course, 'Anna', 'Meier');
        self::enrolled($course, 'Yves', 'Amsler');

        $rows = self::register()->forLesson($course->getTeacher(), $lesson->getId());

        self::assertNotNull($rows);
        self::assertSame(
            ['Amsler Yves', 'Meier Anna', 'Meier Zoe'],
            array_map(static fn (LessonRegisterRow $row): string => $row->lastName.' '.$row->firstName, $rows),
        );
    }

    public function test_foreign_and_unknown_lesson_return_null(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        self::enrolled($course, 'Anna', 'Arnold');

        self::assertNull(self::register()->forLesson(TeacherFactory::createOne(), $lesson->getId()));
        self::assertNull(self::register()->forLesson($course->getTeacher(), Uuid::v7()));
    }

    private static function enrolled(Course $course, string $firstName, string $lastName, ?string $label = null): Student
    {
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher(), 'firstName' => $firstName, 'lastName' => $lastName]);
        EnrollmentFactory::createOne(['course' => $course, 'student' => $student, 'publicCheckinLabel' => $label]);

        return $student;
    }

    /**
     * @param list<LessonRegisterRow> $rows
     *
     * @return list<string>
     */
    private static function lastNames(array $rows): array
    {
        return array_map(static fn (LessonRegisterRow $row): string => $row->lastName, $rows);
    }

    private static function register(): LessonRegister
    {
        return new LessonRegister(self::connection());
    }
}
