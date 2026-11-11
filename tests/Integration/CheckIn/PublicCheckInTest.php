<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Service\CheckInOutcome;
use App\CheckIn\Service\CheckInConfirmation;
use App\CheckIn\Service\CheckInRefusal;
use App\CheckIn\Service\PublicRoster;
use App\CheckIn\Service\RosterLabel;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Credits\ReadModel\Balances;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class PublicCheckInTest extends KernelTestCase
{
    use BuildsCheckInServices;
    use ClockSensitiveTrait;

    private const EMAIL = 'anna.muellerson@example.com';
    private const NOTES = 'Knee injury in March';
    private const LAST_NAME = 'Müllerson';

    public function test_roster_holds_the_lesson_facts_and_the_labels_of_active_students(): void
    {
        self::bootKernel();
        [$teacher, $lesson, $anna, $other, $token] = self::openCheckIn();
        $course = $lesson->getCourse();
        EnrollmentFactory::new()->left()->create(['course' => $course]);
        EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::new()->archived()->create(['teacher' => $teacher])]);

        $roster = self::publicCheckIn()->roster($token);

        self::assertInstanceOf(PublicRoster::class, $roster);
        self::assertSame('Mara Lindqvist', $roster->checkIn->teacherName);
        self::assertSame('Salsa basics', $roster->checkIn->courseName);
        self::assertSame('Europe/Berlin', $roster->checkIn->courseTimezone);
        self::assertTrue($roster->checkIn->lessonId->equals($lesson->getId()));
        self::assertEquals($lesson->getStartsAtUtc(), $roster->checkIn->lessonStartsAtUtc);
        $labels = [];
        foreach ($roster->students as $label) {
            $labels[$label->enrollmentId->toRfc4122()] = $label->label;
        }
        ksort($labels);
        $expected = [
            $anna->getId()->toRfc4122() => 'Anna M.',
            $other->getId()->toRfc4122() => trim($other->getStudent()->getFirstName()).' '.mb_substr(trim($other->getStudent()->getLastName()), 0, 1).'.',
        ];
        ksort($expected);
        self::assertSame($expected, $labels);
        self::assertHoldsNoPrivateData($roster);
    }

    public function test_confirm_records_once_and_answers_with_the_balance(): void
    {
        self::bootKernel();
        [, $lesson, $anna, , $token] = self::openCheckIn();
        CreditGrantFactory::createOne(['enrollment' => $anna, 'quantity' => 10]);

        $first = self::publicCheckIn()->confirm($token, $anna->getId());

        self::assertEquals(new CheckInConfirmation(CheckInOutcome::Recorded, 'Anna M.', 9), $first);
        $rows = self::connection()->fetchAllAssociative('SELECT lesson_session_id, student_id, status, source FROM attendance');
        self::assertCount(1, $rows);
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['lesson_session_id'])->equals($lesson->getId()));
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['student_id'])->equals($anna->getStudent()->getId()));
        self::assertSame(['present', 'student_qr'], [$rows[0]['status'], $rows[0]['source']]);
        self::assertHoldsNoPrivateData($first);

        // Journey C: the same student scans again.
        $second = self::publicCheckIn()->confirm($token, $anna->getId());

        self::assertEquals(new CheckInConfirmation(CheckInOutcome::AlreadyPresent, 'Anna M.', 9), $second);
        self::assertSame(1, self::countRows('attendance'));
    }

    public function test_confirm_never_overwrites_the_teachers_absent_mark(): void
    {
        self::bootKernel();
        [, $lesson, $anna, , $token] = self::openCheckIn();
        CreditGrantFactory::createOne(['enrollment' => $anna, 'quantity' => 10]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $anna->getStudent(), 'status' => AttendanceStatus::Absent]);

        $confirmation = self::publicCheckIn()->confirm($token, $anna->getId());

        self::assertEquals(new CheckInConfirmation(CheckInOutcome::MarkedByTeacher, 'Anna M.', 10), $confirmation);
        self::assertSame(['absent'], self::connection()->fetchFirstColumn('SELECT status FROM attendance'));
    }

    /**
     * @return iterable<string, array{string, CheckInRefusal, bool}> case, refusal, whether roster() refuses too
     */
    public static function refusals(): iterable
    {
        yield 'malformed token' => ['malformed', CheckInRefusal::Malformed, true];
        yield 'unknown token' => ['unknown', CheckInRefusal::Unknown, true];
        yield 'closed window' => ['closed', CheckInRefusal::Closed, true];
        yield 'rotated token' => ['rotated', CheckInRefusal::Closed, true];
        yield 'expired window' => ['expired', CheckInRefusal::Expired, true];
        yield 'cancelled lesson' => ['cancelled', CheckInRefusal::LessonCancelled, true];
        yield 'archived course' => ['archived_course', CheckInRefusal::CourseArchived, true];
        yield 'left enrollment' => ['left', CheckInRefusal::NotEnrolled, false];
        yield 'archived student' => ['archived_student', CheckInRefusal::NotEnrolled, false];
        yield 'enrollment of another course' => ['other_course', CheckInRefusal::NotEnrolled, false];
        yield 'enrollment of another teacher' => ['other_teacher', CheckInRefusal::NotEnrolled, false];
        yield 'random enrollment id' => ['random', CheckInRefusal::NotEnrolled, false];
    }

    /**
     * Journey D: every refusal leaves attendance, credits, audit and balances unchanged.
     */
    #[DataProvider('refusals')]
    public function test_refused_check_in_changes_nothing(string $case, CheckInRefusal $expected, bool $rosterRefused): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson, $anna, , $token] = self::openCheckIn();
        $course = $lesson->getCourse();
        CreditGrantFactory::createOne(['enrollment' => $anna, 'quantity' => 10]);
        $enrollment = $anna;

        switch ($case) {
            case 'malformed':
                $token = 'not-a-token';
                break;
            case 'unknown':
                $token = str_repeat('A', 43);
                break;
            case 'closed':
                self::windows()->close($teacher, $lesson->getId());
                break;
            case 'rotated':
                self::windows()->open($teacher, $lesson->getId());
                break;
            case 'expired':
                self::mockTime('2026-03-10 20:00:00');
                break;
            case 'cancelled':
                self::connection()->executeStatement(
                    "UPDATE lesson_session SET status = 'cancelled', cancelled_at = '2026-03-10 19:05:00' WHERE id = ?",
                    [$lesson->getId()],
                    [UuidType::NAME],
                );
                break;
            case 'archived_course':
                self::connection()->executeStatement("UPDATE course SET archived_at = '2026-03-10 19:05:00' WHERE id = ?", [$course->getId()], [UuidType::NAME]);
                break;
            case 'left':
                $enrollment = EnrollmentFactory::new()->left()->create(['course' => $course]);
                break;
            case 'archived_student':
                $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::new()->archived()->create(['teacher' => $teacher])]);
                break;
            case 'other_course':
                $enrollment = EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher])]);
                break;
            case 'other_teacher':
                $enrollment = EnrollmentFactory::createOne();
                break;
            case 'random':
                $enrollment = null;
                break;
        }
        $enrollmentId = $enrollment?->getId() ?? Uuid::v7();
        CreditGrantFactory::createOne(['enrollment' => $enrollment ?? $anna, 'quantity' => 5]);
        $before = self::snapshot($teacher, $course->getId(), $enrollment);

        self::assertSame($expected, self::publicCheckIn()->confirm($token, $enrollmentId));

        self::assertSame($before, self::snapshot($teacher, $course->getId(), $enrollment));
        self::assertTrue(self::entityManager()->isOpen());
        $roster = self::publicCheckIn()->roster($token);
        if ($rosterRefused) {
            self::assertSame($expected, $roster);
        } else {
            self::assertInstanceOf(PublicRoster::class, $roster);
            self::assertNotContains(
                $enrollmentId->toRfc4122(),
                array_map(static fn (RosterLabel $label): string => $label->enrollmentId->toRfc4122(), $roster->students),
            );
        }
    }

    /**
     * Mara Lindqvist's "Salsa basics" (Europe/Berlin) with Anna Müllerson (email and notes set)
     * and one more student, and an open check-in.
     *
     * @return array{Teacher, LessonSession, Enrollment, Enrollment, string}
     */
    private static function openCheckIn(): array
    {
        [$teacher, $lesson, $anna, $other] = self::openLesson(
            ['name' => 'Mara Lindqvist'],
            ['name' => 'Salsa basics', 'timezone' => 'Europe/Berlin'],
            ['firstName' => 'Anna', 'lastName' => self::LAST_NAME, 'email' => self::EMAIL, 'notes' => self::NOTES],
        );
        $opened = self::windows()->open($teacher, $lesson->getId());

        return [$teacher, $lesson, $anna, $other, $opened->token];
    }

    /**
     * Row counts of attendance, credit_grant and audit_event, the balances of the lesson's course
     * and the balance of the enrollment under test (read for its own teacher).
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Teacher $teacher, Uuid $courseId, ?Enrollment $enrollment): array
    {
        $balances = new Balances(self::connection());
        $course = [];
        foreach ($balances->forCourse($teacher, $courseId) as $id => $balance) {
            $course[$id] = $balance->balance;
        }

        return [
            'attendance' => self::countRows('attendance'),
            'credit_grant' => self::countRows('credit_grant'),
            'audit_event' => self::countRows('audit_event'),
            'course' => $course,
            'enrollment' => null === $enrollment
                ? null
                : $balances->forEnrollment($enrollment->getCourse()->getTeacher(), $enrollment->getId())?->balance,
        ];
    }

    private static function assertHoldsNoPrivateData(object $result): void
    {
        $serialized = serialize($result);
        self::assertStringNotContainsString(self::EMAIL, $serialized);
        self::assertStringNotContainsString(self::NOTES, $serialized);
        self::assertStringNotContainsString(self::LAST_NAME, $serialized);
    }
}
