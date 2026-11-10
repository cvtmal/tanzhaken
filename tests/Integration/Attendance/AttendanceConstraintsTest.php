<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AttendanceConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_a_student_has_one_attendance_per_lesson(): void
    {
        self::bootKernel();
        $attendance = AttendanceFactory::createOne();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/attendance\.lesson_session_id, attendance\.student_id/');

        self::insertRow('attendance', self::attendanceRow($attendance->getLessonSession()->getId(), $attendance->getStudent()->getId()));
    }

    public function test_attendance_needs_an_existing_lesson(): void
    {
        self::bootKernel();
        $student = StudentFactory::createOne();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('attendance', self::attendanceRow(Uuid::v7(), $student->getId()));
    }

    public function test_attendance_needs_an_existing_student(): void
    {
        self::bootKernel();
        $lesson = LessonSessionFactory::createOne();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('attendance', self::attendanceRow($lesson->getId(), Uuid::v7()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function attendanceRow(Uuid $lessonId, Uuid $studentId): array
    {
        $at = new \DateTimeImmutable('2026-01-06 19:00:00');

        return [
            'id' => Uuid::v7(),
            'lesson_session_id' => $lessonId,
            'student_id' => $studentId,
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::Teacher,
            'marked_at' => $at,
            'updated_at' => $at,
        ];
    }
}
