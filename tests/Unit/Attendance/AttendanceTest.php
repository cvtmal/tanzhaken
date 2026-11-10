<?php

declare(strict_types=1);

namespace App\Tests\Unit\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\Attendance;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\InvalidAttendance;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use PHPUnit\Framework\TestCase;

final class AttendanceTest extends TestCase
{
    public function test_it_records_marked_and_updated_at_now(): void
    {
        $teacher = self::teacher('ann@example.com');
        $attendance = new Attendance(self::lesson($teacher), new Student($teacher, 'Mia', 'Example', self::now()), AttendanceStatus::Present, AttendanceSource::StudentQr, self::now());

        self::assertEquals(self::now(), $attendance->getMarkedAt());
        self::assertEquals(self::now(), $attendance->getUpdatedAt());
        self::assertSame(AttendanceStatus::Present, $attendance->getStatus());
        self::assertSame(AttendanceSource::StudentQr, $attendance->getSource());
        self::assertTrue($teacher->getId()->equals($attendance->ownerId()));
    }

    public function test_it_refuses_a_student_of_another_teacher(): void
    {
        $lesson = self::lesson(self::teacher('ann@example.com'));
        $student = new Student(self::teacher('bob@example.com'), 'Mia', 'Example', self::now());

        $this->expectException(InvalidAttendance::class);

        new Attendance($lesson, $student, AttendanceStatus::Present, AttendanceSource::Teacher, self::now());
    }

    public function test_change_updates_updated_at_only(): void
    {
        $teacher = self::teacher('ann@example.com');
        $attendance = new Attendance(self::lesson($teacher), new Student($teacher, 'Mia', 'Example', self::now()), AttendanceStatus::Present, AttendanceSource::StudentQr, self::now());
        $later = self::now()->modify('+2 hours');

        $attendance->change(AttendanceStatus::Absent, AttendanceSource::Teacher, $later);

        self::assertSame(AttendanceStatus::Absent, $attendance->getStatus());
        self::assertSame(AttendanceSource::Teacher, $attendance->getSource());
        self::assertEquals(self::now(), $attendance->getMarkedAt());
        self::assertEquals($later, $attendance->getUpdatedAt());
    }

    private static function teacher(string $email): Teacher
    {
        return new Teacher('Ann Example', $email, 'hash', self::now());
    }

    private static function lesson(Teacher $teacher): LessonSession
    {
        $course = new Course($teacher, 'Salsa', 'Europe/Zurich', 3, new \DateTimeImmutable('19:30'), 60, new \DateTimeImmutable('2026-09-01'), self::now());

        return new LessonSession($course, new \DateTimeImmutable('2026-11-11 18:30:00'), new \DateTimeImmutable('2026-11-11 19:30:00'));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 10:00:00');
    }
}
