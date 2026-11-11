<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\Teacher;
use App\Account\Service\AccountDeletion;
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
 * ACC-6 / #18 Done-when: AccountDeletion removes every row of the teacher in all ten tables and
 * leaves another teacher's rows untouched. A new teacher-owned table belongs in TABLES and graph().
 */
#[ResetDatabase]
final class AccountDeletionTest extends KernelTestCase
{
    use DbalRows;

    private const TABLES = [
        'teacher', 'audit_event', 'student', 'course', 'enrollment', 'lesson_session',
        'credit_grant', 'attendance', 'check_in_window', 'password_reset_request',
    ];

    public function test_it_removes_every_row_of_the_teacher_and_nothing_else(): void
    {
        self::bootKernel();
        $doomed = TeacherFactory::createOne();
        self::graph($doomed, 'a');
        $kept = TeacherFactory::createOne();
        $keptIds = self::graph($kept, 'b');

        foreach (self::TABLES as $table) {
            self::assertSame(2, self::countRows($table), $table);
        }

        self::deletion()->delete($doomed);

        foreach (self::TABLES as $table) {
            self::assertSame([$keptIds[$table]], self::rowIds($table), $table);
        }
    }

    public function test_it_refuses_a_teacher_that_is_already_gone(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        self::deletion()->delete($teacher);

        $this->expectException(\LogicException::class);
        self::deletion()->delete($teacher);
    }

    /**
     * One row in every table for the teacher.
     *
     * @return array<string, string> table => the row's id (RFC 4122)
     */
    private static function graph(Teacher $teacher, string $hashChar): array
    {
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $grant = CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        $attendance = AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student]);
        $window = CheckInWindowFactory::createOne(['lessonSession' => $lesson]);
        $audit = AuditEventFactory::createOne(['teacher' => $teacher]);

        $now = Clock::get()->now();
        $reset = new PasswordResetRequest($teacher, str_repeat($hashChar, 64), $now, $now->modify('+60 minutes'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($reset);
        $em->flush();

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
            'password_reset_request' => $reset->getId(),
        ]);
    }

    private static function deletion(): AccountDeletion
    {
        $deletion = self::getContainer()->get(AccountDeletion::class);
        self::assertInstanceOf(AccountDeletion::class, $deletion);

        return $deletion;
    }
}
