<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\ReadModel\StudentAttendanceHistory;
use App\Attendance\ReadModel\StudentAttendanceRow;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentAttendanceHistoryTest extends KernelTestCase
{
    use DbalRows;

    public function test_history_spans_courses_newest_first(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $salsa = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa', 'timezone' => 'Europe/Zurich']);
        $tango = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Tango', 'timezone' => 'America/New_York']);
        EnrollmentFactory::createOne(['course' => $salsa, 'student' => $student]);
        EnrollmentFactory::createOne(['course' => $tango, 'student' => $student]);
        $salsaOld = self::lesson($salsa, '2026-03-03 18:00:00');
        $tangoMid = self::lesson($tango, '2026-03-05 23:00:00');
        $salsaNew = LessonSessionFactory::new()->completed()->create(['course' => $salsa, 'startsAtUtc' => new \DateTimeImmutable('2026-03-10 18:00:00'), 'endsAtUtc' => new \DateTimeImmutable('2026-03-10 19:00:00')]);
        AttendanceFactory::createOne(['lessonSession' => $salsaOld, 'student' => $student, 'status' => AttendanceStatus::Absent, 'source' => AttendanceSource::Teacher]);
        AttendanceFactory::createOne(['lessonSession' => $tangoMid, 'student' => $student, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::StudentQr]);
        AttendanceFactory::createOne(['lessonSession' => $salsaNew, 'student' => $student, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Teacher]);

        $rows = self::history()->forStudent($teacher, $student->getId());

        self::assertCount(3, $rows);
        self::assertTrue($rows[0]->lessonId->equals($salsaNew->getId()));
        self::assertTrue($rows[1]->lessonId->equals($tangoMid->getId()));
        self::assertTrue($rows[2]->lessonId->equals($salsaOld->getId()));
        self::assertSame(['Salsa', 'Tango', 'Salsa'], array_map(static fn (StudentAttendanceRow $row): string => $row->courseName, $rows));
        self::assertSame('America/New_York', $rows[1]->courseTimezone);
        self::assertTrue($rows[1]->courseId->equals($tango->getId()));
        self::assertSame('2026-03-05 23:00:00', $rows[1]->startsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame([LessonStatus::Completed, LessonStatus::Scheduled, LessonStatus::Scheduled], array_map(static fn (StudentAttendanceRow $row): LessonStatus => $row->lessonStatus, $rows));
        self::assertSame([AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Absent], array_map(static fn (StudentAttendanceRow $row): AttendanceStatus => $row->status, $rows));
        self::assertSame(AttendanceSource::StudentQr, $rows[1]->source);
    }

    public function test_foreign_student_returns_empty(): void
    {
        self::bootKernel();
        $attendance = AttendanceFactory::createOne();

        self::assertSame([], self::history()->forStudent(TeacherFactory::createOne(), $attendance->getStudent()->getId()));
        self::assertCount(1, self::history()->forStudent($attendance->getLessonSession()->getCourse()->getTeacher(), $attendance->getStudent()->getId()));
    }

    public function test_other_students_marks_are_excluded(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $student = EnrollmentFactory::createOne(['course' => $course])->getStudent();
        $other = EnrollmentFactory::createOne(['course' => $course])->getStudent();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $other]);

        self::assertSame([], self::history()->forStudent($course->getTeacher(), $student->getId()));
    }

    private static function lesson(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);
    }

    private static function history(): StudentAttendanceHistory
    {
        return new StudentAttendanceHistory(self::connection());
    }
}
