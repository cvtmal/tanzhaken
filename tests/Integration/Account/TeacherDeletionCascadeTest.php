<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\AuditEventFactory;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * D3: every foreign key cascades from `teacher`, so one `DELETE FROM teacher` removes the
 * teacher's whole graph and nothing else; the domain's own "removals" only set timestamps.
 */
#[ResetDatabase]
final class TeacherDeletionCascadeTest extends KernelTestCase
{
    use DbalRows;

    private const TABLES = [
        'teacher', 'audit_event', 'student', 'course', 'enrollment',
        'lesson_session', 'credit_grant', 'attendance', 'check_in_window',
    ];

    public function test_deleting_a_teacher_removes_exactly_their_rows(): void
    {
        self::bootKernel();
        $doomed = TeacherFactory::createOne();
        self::graph($doomed);
        $kept = TeacherFactory::createOne();
        $keptIds = self::graph($kept);

        foreach (self::TABLES as $table) {
            self::assertSame(2, self::countRows($table), $table);
        }

        self::connection()->executeStatement('DELETE FROM teacher WHERE id = ?', [$doomed->getId()->toBinary()]);

        foreach (self::TABLES as $table) {
            self::assertSame([$keptIds[$table]], self::rowIds($table), $table);
        }
    }

    public function test_archive_leave_void_and_cancel_keep_the_rows(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $grant = CreditGrantFactory::createOne(['enrollment' => $enrollment]);

        $now = Clock::get()->now();
        $student->archive($now);
        $course->archive($now);
        $enrollment->leave($now);
        $grant->void($now);
        $lesson->cancel($now);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();

        $stamps = [
            'student' => 'archived_at',
            'course' => 'archived_at',
            'enrollment' => 'left_at',
            'credit_grant' => 'voided_at',
            'lesson_session' => 'cancelled_at',
        ];
        foreach ($stamps as $table => $column) {
            self::assertSame(1, self::countRows($table), $table);
            self::assertNotNull(self::connection()->fetchOne(\sprintf('SELECT %s FROM %s', $column, $table)), $table);
        }
        self::assertSame('cancelled', self::connection()->fetchOne('SELECT status FROM lesson_session'));
    }

    /**
     * One row in every table for the teacher.
     *
     * @return array<string, string> table => the row's id (RFC 4122)
     */
    private static function graph(Teacher $teacher): array
    {
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $grant = CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        $attendance = AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student]);
        $window = CheckInWindowFactory::createOne(['lessonSession' => $lesson]);
        $audit = AuditEventFactory::createOne(['teacher' => $teacher]);

        return array_map(static fn (Uuid $id): string => $id->toRfc4122(), [
            'teacher' => $teacher->getId(),
            'audit_event' => $audit->getId(),
            'student' => $student->getId(),
            'course' => $course->getId(),
            'enrollment' => $enrollment->getId(),
            'lesson_session' => $lesson->getId(),
            'credit_grant' => $grant->getId(),
            'attendance' => $attendance->getId(),
            'check_in_window' => $window->getId(),
        ]);
    }
}
