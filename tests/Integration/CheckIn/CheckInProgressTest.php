<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\CheckIn\ReadModel\CheckInProgress;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The counts of the teacher's QR page (D5) and the open window of the lesson-page slot (D6, S4).
 */
#[ResetDatabase]
final class CheckInProgressTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const NOW = '2026-11-10 18:10:00';

    public function test_counts_take_the_active_unarchived_enrollments_and_their_present_marks(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();
        $byQr = self::enrolled($course, 'Alex');
        $byTeacher = self::enrolled($course, 'Sam');
        $absent = self::enrolled($course, 'Lena');
        self::enrolled($course, 'Otto');
        $left = StudentFactory::createOne(['teacher' => $course->getTeacher(), 'firstName' => 'Nina']);
        EnrollmentFactory::new()->left()->create(['course' => $course, 'student' => $left]);
        $archived = StudentFactory::new()->archived()->create(['teacher' => $course->getTeacher(), 'firstName' => 'Ida']);
        EnrollmentFactory::createOne(['course' => $course, 'student' => $archived]);

        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $byQr, 'source' => AttendanceSource::StudentQr]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $byTeacher, 'source' => AttendanceSource::Teacher]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $absent, 'status' => AttendanceStatus::Absent]);
        // Present marks of a left and an archived student are not counted: they are not on the roster.
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $left]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $archived]);
        // A present mark for another lesson of the course does not count for this one.
        $other = LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $lesson->getStartsAtUtc()->modify('+7 days'), 'endsAtUtc' => $lesson->getEndsAtUtc()->modify('+7 days')]);
        AttendanceFactory::createOne(['lessonSession' => $other, 'student' => $absent]);

        $counts = self::progress()->counts($lesson->getId(), $course->getId());

        self::assertSame(2, $counts->checkedIn);
        self::assertSame(4, $counts->enrolled);
    }

    public function test_counts_of_a_course_without_students_are_zero(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();

        $counts = self::progress()->counts($lesson->getId(), $course->getId());

        self::assertSame(0, $counts->checkedIn);
        self::assertSame(0, $counts->enrolled);
    }

    public function test_open_until_is_the_expiry_of_the_active_window(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();
        CheckInWindowFactory::createOne(['lessonSession' => $lesson]);

        $until = self::progress()->openUntil($course->getTeacher()->getId(), $lesson->getId(), self::now());

        self::assertNotNull($until);
        self::assertSame('2026-11-10 19:10:00 UTC', $until->format('Y-m-d H:i:s T'));
    }

    public function test_open_until_is_null_for_a_closed_window(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();
        CheckInWindowFactory::new()->closed()->create(['lessonSession' => $lesson]);

        self::assertNull(self::progress()->openUntil($course->getTeacher()->getId(), $lesson->getId(), self::now()));
    }

    public function test_open_until_is_null_for_an_expired_window(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();
        CheckInWindowFactory::createOne(['lessonSession' => $lesson, 'openedAt' => self::now()->modify('-70 minutes'), 'expiresAt' => self::now()->modify('-10 minutes')]);

        self::assertNull(self::progress()->openUntil($course->getTeacher()->getId(), $lesson->getId(), self::now()));
    }

    public function test_open_until_is_null_at_the_expiry_itself(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();
        CheckInWindowFactory::createOne(['lessonSession' => $lesson, 'openedAt' => self::now()->modify('-60 minutes'), 'expiresAt' => self::now()]);

        self::assertNull(self::progress()->openUntil($course->getTeacher()->getId(), $lesson->getId(), self::now()));
    }

    public function test_open_until_is_null_for_another_teachers_lesson(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [, $lesson] = self::courseWithLesson();
        CheckInWindowFactory::createOne(['lessonSession' => $lesson]);
        $other = TeacherFactory::createOne();

        self::assertNull(self::progress()->openUntil($other->getId(), $lesson->getId(), self::now()));
    }

    public function test_open_until_is_null_without_a_window(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        [$course, $lesson] = self::courseWithLesson();

        self::assertNull(self::progress()->openUntil($course->getTeacher()->getId(), $lesson->getId(), self::now()));
        self::assertNull(self::progress()->openUntil($course->getTeacher()->getId(), Uuid::v7(), self::now()));
    }

    /**
     * @return array{Course, LessonSession}
     */
    private static function courseWithLesson(): array
    {
        $course = CourseFactory::createOne(['teacher' => TeacherFactory::createOne()]);
        $startsAt = new \DateTimeImmutable('2026-11-10 18:00:00', new \DateTimeZone('UTC'));
        $lesson = LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);

        return [$course, $lesson];
    }

    private static function enrolled(Course $course, string $firstName): Student
    {
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher(), 'firstName' => $firstName]);
        EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);

        return $student;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private static function progress(): CheckInProgress
    {
        $progress = self::getContainer()->get(CheckInProgress::class);
        self::assertInstanceOf(CheckInProgress::class, $progress);

        return $progress;
    }
}
