<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\InvalidAttendance;
use App\Attendance\Service\CheckInOutcome;
use App\Attendance\Service\CheckInRecorder;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInRecorderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    public function test_first_scan_is_recorded_as_present_from_student_qr(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:05:00');
        [$lesson, $student] = self::enrolledStudentAndLesson();

        $outcome = self::recorder()->recordCheckIn($lesson->getId(), $student->getId());

        self::assertSame(CheckInOutcome::Recorded, $outcome);
        $rows = self::connection()->fetchAllAssociative('SELECT * FROM attendance');
        self::assertCount(1, $rows);
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['lesson_session_id'])->equals($lesson->getId()));
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['student_id'])->equals($student->getId()));
        self::assertSame('present', $rows[0]['status']);
        self::assertSame('student_qr', $rows[0]['source']);
        self::assertSame('2026-03-10 20:05:00', $rows[0]['marked_at']);
        self::assertSame('2026-03-10 20:05:00', $rows[0]['updated_at']);
    }

    public function test_second_scan_is_already_present_and_keeps_one_row(): void
    {
        self::bootKernel();
        [$lesson, $student] = self::enrolledStudentAndLesson();

        self::recorder()->recordCheckIn($lesson->getId(), $student->getId());
        $outcome = self::recorder()->recordCheckIn($lesson->getId(), $student->getId());

        self::assertSame(CheckInOutcome::AlreadyPresent, $outcome);
        self::assertSame(1, self::countRows('attendance'));
    }

    public function test_scan_after_teacher_present_is_already_present(): void
    {
        self::bootKernel();
        [$lesson, $student] = self::enrolledStudentAndLesson();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Teacher]);

        $outcome = self::recorder()->recordCheckIn($lesson->getId(), $student->getId());

        self::assertSame(CheckInOutcome::AlreadyPresent, $outcome);
        self::assertSame(['present', 'teacher'], self::onlyRowStatusAndSource());
    }

    public function test_scan_after_teacher_absent_is_marked_by_teacher_and_row_unchanged(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:00:00');
        [$lesson, $student] = self::enrolledStudentAndLesson();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => AttendanceStatus::Absent, 'source' => AttendanceSource::Teacher]);
        self::mockTime('2026-03-10 20:10:00');

        $outcome = self::recorder()->recordCheckIn($lesson->getId(), $student->getId());

        self::assertSame(CheckInOutcome::MarkedByTeacher, $outcome);
        self::assertSame(['absent', 'teacher'], self::onlyRowStatusAndSource());
        self::assertSame('2026-03-10 20:00:00', self::connection()->fetchOne('SELECT updated_at FROM attendance'));
    }

    public function test_cancelled_lesson_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $course]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);

        self::assertRefused($lesson->getId(), $enrollment->getStudent()->getId());
    }

    public function test_left_enrollment_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $enrollment = EnrollmentFactory::new()->left()->create(['course' => $course]);

        self::assertRefused($lesson->getId(), $enrollment->getStudent()->getId());
    }

    public function test_student_not_enrolled_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher()]);

        self::assertRefused($lesson->getId(), $student->getId());
    }

    public function test_unknown_lesson_is_refused(): void
    {
        self::bootKernel();
        [, $student] = self::enrolledStudentAndLesson();

        self::assertRefused(Uuid::v7(), $student->getId());
    }

    private static function assertRefused(Uuid $lessonId, Uuid $studentId): void
    {
        try {
            self::recorder()->recordCheckIn($lessonId, $studentId);
            self::fail('Expected InvalidAttendance.');
        } catch (InvalidAttendance) {
            self::assertSame(0, self::countRows('attendance'));
        }
    }

    /**
     * @return array{LessonSession, Student}
     */
    private static function enrolledStudentAndLesson(): array
    {
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);

        return [$lesson, $enrollment->getStudent()];
    }

    /**
     * @return array{mixed, mixed}
     */
    private static function onlyRowStatusAndSource(): array
    {
        $rows = self::connection()->fetchAllAssociative('SELECT status, source FROM attendance');
        self::assertCount(1, $rows);

        return [$rows[0]['status'], $rows[0]['source']];
    }

    private static function recorder(): CheckInRecorder
    {
        // Built by hand until a consumer (#8) makes the service reachable in the container (D-026).
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new CheckInRecorder(self::connection(), $clock);
    }
}
