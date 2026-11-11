<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\Attendance;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\AttendanceNotFound;
use App\Attendance\Exception\InvalidAttendance;
use App\Attendance\Repository\AttendanceRepository;
use App\Attendance\Service\AttendanceMarker;
use App\Courses\Entity\LessonSession;
use App\Courses\Repository\LessonSessionRepository;
use App\Shared\Audit\AuditLogger;
use App\Students\Entity\Student;
use App\Students\Repository\StudentRepository;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AttendanceMarkerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    public function test_mark_present_creates_teacher_row_and_audit(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:30:00');
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();

        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Present);

        $rows = self::connection()->fetchAllAssociative('SELECT id, status, source, marked_at FROM attendance');
        self::assertCount(1, $rows);
        self::assertSame(['present', 'teacher', '2026-03-10 20:30:00'], [$rows[0]['status'], $rows[0]['source'], $rows[0]['marked_at']]);
        $audits = self::auditRows();
        self::assertCount(1, $audits);
        self::assertSame('attendance.marked', $audits[0]['action']);
        self::assertSame('teacher', $audits[0]['actor_type']);
        self::assertSame('attendance', $audits[0]['entity_type']);
        self::assertTrue(Uuid::fromBinary((string) $audits[0]['entity_id'])->equals(Uuid::fromBinary((string) $rows[0]['id'])));
        self::assertTrue(Uuid::fromBinary((string) $audits[0]['teacher_id'])->equals($teacher->getId()));
        self::assertSame([
            'lesson_id' => $lesson->getId()->toRfc4122(),
            'student_id' => $student->getId()->toRfc4122(),
            'status' => 'present',
            'previous_status' => null,
            'previous_source' => null,
            'reason' => null,
        ], $audits[0]['details']);
    }

    public function test_journey_f_present_to_absent_lowers_present_count_and_is_logged(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();
        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Present);
        self::assertSame(1, self::presentCount($lesson));

        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Absent, ' Mistake ');

        self::assertSame(1, self::countRows('attendance'));
        self::assertSame(0, self::presentCount($lesson));
        self::assertSame('absent', self::connection()->fetchOne('SELECT status FROM attendance'));
        $audits = self::auditRows();
        self::assertCount(2, $audits);
        $latest = $audits[1]['details'];
        self::assertSame('present', $latest['previous_status']);
        self::assertSame('teacher', $latest['previous_source']);
        self::assertSame('absent', $latest['status']);
        self::assertSame('Mistake', $latest['reason']);
    }

    public function test_teacher_mark_overrides_a_student_scan(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::StudentQr]);

        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Absent);

        self::assertSame(['status' => 'absent', 'source' => 'teacher'], self::connection()->fetchAssociative('SELECT status, source FROM attendance'));
        $audits = self::auditRows();
        self::assertCount(1, $audits);
        self::assertSame('student_qr', $audits[0]['details']['previous_source']);
        self::assertSame('present', $audits[0]['details']['previous_status']);
    }

    public function test_same_status_again_is_a_no_op(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:30:00');
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();
        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Present);
        self::mockTime('2026-03-10 20:45:00');

        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Present);

        self::assertSame(1, self::countRows('audit_event'));
        self::assertSame('2026-03-10 20:30:00', self::connection()->fetchOne('SELECT updated_at FROM attendance'));
    }

    public function test_clear_removes_row_and_logs_reason(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();
        $attendance = AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => AttendanceStatus::Absent, 'source' => AttendanceSource::Teacher]);

        self::marker()->clear($teacher, $lesson->getId(), $student->getId(), 'Wrong student');

        self::assertSame(0, self::countRows('attendance'));
        $audits = self::auditRows();
        self::assertCount(1, $audits);
        self::assertSame('attendance.cleared', $audits[0]['action']);
        self::assertTrue(Uuid::fromBinary((string) $audits[0]['entity_id'])->equals($attendance->getId()));
        self::assertSame([
            'lesson_id' => $lesson->getId()->toRfc4122(),
            'student_id' => $student->getId()->toRfc4122(),
            'previous_status' => 'absent',
            'previous_source' => 'teacher',
            'reason' => 'Wrong student',
        ], $audits[0]['details']);
    }

    public function test_clear_is_allowed_on_a_left_enrollment(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $student = EnrollmentFactory::new()->left()->create(['course' => $course])->getStudent();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student]);

        self::marker()->clear($course->getTeacher(), $lesson->getId(), $student->getId());

        self::assertSame(0, self::countRows('attendance'));
        self::assertSame(1, self::countRows('audit_event'));
    }

    public function test_clear_is_allowed_on_a_cancelled_lesson(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $course]);
        $student = EnrollmentFactory::createOne(['course' => $course])->getStudent();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student]);

        self::marker()->clear($course->getTeacher(), $lesson->getId(), $student->getId());

        self::assertSame(0, self::countRows('attendance'));
        self::assertSame(1, self::countRows('audit_event'));
    }

    public function test_foreign_lesson_is_not_found(): void
    {
        self::bootKernel();
        [$teacher, , $student] = self::enrolledStudentAndLesson();
        $foreignLesson = LessonSessionFactory::createOne();

        self::assertRefused(AttendanceNotFound::class, static fn () => self::marker()->mark($teacher, $foreignLesson->getId(), $student->getId(), AttendanceStatus::Present));
    }

    public function test_foreign_student_is_not_found(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::enrolledStudentAndLesson();
        $foreignStudent = StudentFactory::createOne();

        self::assertRefused(AttendanceNotFound::class, static fn () => self::marker()->mark($teacher, $lesson->getId(), $foreignStudent->getId(), AttendanceStatus::Present));
    }

    public function test_unknown_lesson_is_not_found(): void
    {
        self::bootKernel();
        [$teacher, , $student] = self::enrolledStudentAndLesson();

        self::assertRefused(AttendanceNotFound::class, static fn () => self::marker()->mark($teacher, Uuid::v7(), $student->getId(), AttendanceStatus::Present));
        self::assertRefused(AttendanceNotFound::class, static fn () => self::marker()->clear($teacher, Uuid::v7(), $student->getId()));
    }

    public function test_student_not_enrolled_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher()]);

        self::assertRefused(InvalidAttendance::class, static fn () => self::marker()->mark($course->getTeacher(), $lesson->getId(), $student->getId(), AttendanceStatus::Present));
    }

    public function test_left_enrollment_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $student = EnrollmentFactory::new()->left()->create(['course' => $course])->getStudent();

        self::assertRefused(InvalidAttendance::class, static fn () => self::marker()->mark($course->getTeacher(), $lesson->getId(), $student->getId(), AttendanceStatus::Absent));
    }

    public function test_cancelled_lesson_is_refused(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $course]);
        $student = EnrollmentFactory::createOne(['course' => $course])->getStudent();

        self::assertRefused(InvalidAttendance::class, static fn () => self::marker()->mark($course->getTeacher(), $lesson->getId(), $student->getId(), AttendanceStatus::Present));
    }

    public function test_clear_without_mark_is_refused(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();

        self::assertRefused(InvalidAttendance::class, static fn () => self::marker()->clear($teacher, $lesson->getId(), $student->getId()));
    }

    public function test_reason_too_long_is_refused(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();

        self::assertRefused(InvalidAttendance::class, static fn () => self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Absent, str_repeat('ä', 256)));

        self::marker()->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Absent, str_repeat('ä', 255));
        self::assertSame(1, self::countRows('attendance'));
        self::assertSame(str_repeat('ä', 255), self::auditRows()[0]['details']['reason']);
    }

    public function test_concurrent_insert_surfaces_as_changed_concurrently(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $student] = self::enrolledStudentAndLesson();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::StudentQr]);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        // A scan that is not yet visible to the lookup: the insert then hits the unique key.
        $blind = new class($registry) extends AttendanceRepository {
            public function findOneForLessonAndStudent(LessonSession $lesson, Student $student): ?Attendance
            {
                return null;
            }
        };

        try {
            self::marker($blind)->mark($teacher, $lesson->getId(), $student->getId(), AttendanceStatus::Absent);
            self::fail('Expected InvalidAttendance.');
        } catch (InvalidAttendance $e) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $e->getPrevious());
        }
        self::assertSame(['status' => 'present', 'source' => 'student_qr'], self::connection()->fetchAssociative('SELECT status, source FROM attendance'));
        self::assertSame(0, self::countRows('audit_event'));
    }

    /**
     * Asserts the refusal, that nothing was written, and that the EntityManager is still
     * open (D-027: refusals are thrown before the transaction).
     *
     * @param class-string<\Throwable> $exception
     */
    private static function assertRefused(string $exception, callable $call): void
    {
        $attendances = self::countRows('attendance');
        try {
            $call();
            self::fail(\sprintf('Expected %s.', $exception));
        } catch (AttendanceNotFound|InvalidAttendance $e) {
            self::assertInstanceOf($exception, $e);
        }
        self::assertSame($attendances, self::countRows('attendance'));
        self::assertSame(0, self::countRows('audit_event'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    /**
     * @return array{Teacher, LessonSession, Student}
     */
    private static function enrolledStudentAndLesson(): array
    {
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $lesson = LessonSessionFactory::createOne(['course' => $course]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);

        return [$teacher, $lesson, $enrollment->getStudent()];
    }

    private static function presentCount(LessonSession $lesson): int
    {
        return (int) self::connection()->fetchOne(
            "SELECT COUNT(*) FROM attendance WHERE lesson_session_id = :lesson AND status = 'present'",
            ['lesson' => $lesson->getId()->toBinary()],
        );
    }

    /**
     * @return list<array{action: mixed, actor_type: mixed, entity_type: mixed, entity_id: mixed, teacher_id: mixed, details: array<string, mixed>}>
     */
    private static function auditRows(): array
    {
        $rows = self::connection()->fetchAllAssociative('SELECT action, actor_type, entity_type, entity_id, teacher_id, details_json FROM audit_event ORDER BY id');

        return array_map(static function (array $row): array {
            $details = json_decode((string) $row['details_json'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($details);

            /* @var array<string, mixed> $details */
            return [
                'action' => $row['action'],
                'actor_type' => $row['actor_type'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'teacher_id' => $row['teacher_id'],
                'details' => $details,
            ];
        }, $rows);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private static function marker(?AttendanceRepository $attendances = null): AttendanceMarker
    {
        // Built by hand until a consumer (#13) makes the service reachable in the container (D-026).
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $lessons = self::getContainer()->get(LessonSessionRepository::class);
        self::assertInstanceOf(LessonSessionRepository::class, $lessons);
        $students = self::getContainer()->get(StudentRepository::class);
        self::assertInstanceOf(StudentRepository::class, $students);
        if (null === $attendances) {
            $attendances = self::getContainer()->get(AttendanceRepository::class);
            self::assertInstanceOf(AttendanceRepository::class, $attendances);
        }

        return new AttendanceMarker(self::entityManager(), $lessons, $students, $attendances, new AuditLogger(self::connection(), $clock), $clock);
    }
}
