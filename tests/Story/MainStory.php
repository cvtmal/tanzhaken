<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\CheckIn\Entity\CheckInWindow;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Credits\Entity\CreditGrant;
use App\Shared\Audit\ActorType;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\AuditEventFactory;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Demo data for `make seed` (D14): one teacher, two courses, eight students, nine enrollments
 * (one left), credit grants (one voided), past lessons with attendance (one cancelled), future
 * lessons, one closed check-in window and three audit events. Every name is invented; every
 * date is relative to the clock's now. Login: demo@example.com / TeacherFactory::DEMO_PASSWORD.
 *
 * Balances (credits of non-voided grants minus `present` at non-cancelled lessons):
 * Salsa / Noemi Frei 8 − 6 = 2 ("Low"), Salsa / Elias Huber 5 − 6 = −1 ("Renewal needed").
 */
#[AsFixture(name: 'main')]
final class MainStory extends Story
{
    private const ZONE = 'Europe/Zurich';

    private const WEEKDAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    private const STUDENTS = [
        ['Lena', 'Brunner'],
        ['Noemi', 'Frei'],
        ['Elias', 'Huber'],
        ['Jonas', 'Keller'],
        ['Sofia', 'Marino'],
        ['Luca', 'Weber'],
        ['Alina', 'Meier'],
        ['David', 'Steiner'],
    ];

    public function build(): void
    {
        $now = Clock::get()->now();
        $zone = new \DateTimeZone(self::ZONE);
        $startsOn = $now->setTimezone($zone)->modify('-8 weeks')->setTime(0, 0);
        $joinedAt = $startsOn->setTimezone(new \DateTimeZone('UTC'))->modify('-2 days')->setTime(18, 0);

        $teacher = TeacherFactory::createOne([
            'name' => 'Mara Lindqvist',
            'email' => 'demo@example.com',
            'passwordHash' => TeacherFactory::DEMO_PASSWORD_HASH,
            'timezone' => self::ZONE,
            'now' => $joinedAt->modify('-30 days'),
        ]);

        $salsa = CourseFactory::createOne([
            'teacher' => $teacher,
            'name' => 'Salsa Beginners',
            'description' => 'Basic steps, turns and partner work for new dancers.',
            'location' => 'Studio 1',
            'timezone' => self::ZONE,
            'weekday' => 2,
            'localStartTime' => new \DateTimeImmutable('1970-01-01 19:00:00'),
            'durationMinutes' => 60,
            'startsOn' => $startsOn,
            'now' => $joinedAt->modify('-7 days'),
        ]);
        $tango = CourseFactory::createOne([
            'teacher' => $teacher,
            'name' => 'Tango Intermediate',
            'description' => 'Ochos, giros and musicality for dancers with a year of tango.',
            'location' => 'Studio 2',
            'timezone' => self::ZONE,
            'weekday' => 4,
            'localStartTime' => new \DateTimeImmutable('1970-01-01 20:15:00'),
            'durationMinutes' => 90,
            'startsOn' => $startsOn,
            'now' => $joinedAt->modify('-7 days'),
        ]);

        $students = [];
        foreach (self::STUDENTS as [$firstName, $lastName]) {
            $students[] = StudentFactory::createOne([
                'teacher' => $teacher,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => strtolower($firstName.'.'.$lastName).'@example.com',
                'now' => $joinedAt,
            ]);
        }

        // Salsa: students 0–4; Tango: students 4–7 (Sofia Marino dances both); David Steiner left Tango.
        $salsaEnrollments = [];
        foreach (\array_slice($students, 0, 5) as $student) {
            $salsaEnrollments[] = EnrollmentFactory::createOne(['course' => $salsa, 'student' => $student, 'now' => $joinedAt]);
        }
        $tangoEnrollments = [];
        foreach (\array_slice($students, 4, 3) as $student) {
            $tangoEnrollments[] = EnrollmentFactory::createOne(['course' => $tango, 'student' => $student, 'now' => $joinedAt]);
        }
        $tangoEnrollments[] = EnrollmentFactory::new()->left()->create(['course' => $tango, 'student' => $students[7], 'now' => $joinedAt]);

        $grants = [];
        foreach ([...$salsaEnrollments, ...$tangoEnrollments] as $i => $enrollment) {
            $quantity = match ($i) {
                1 => 8,
                2 => 5,
                default => CreditGrant::DEFAULT_QUANTITY,
            };
            $grants[] = CreditGrantFactory::createOne([
                'enrollment' => $enrollment,
                'now' => $joinedAt,
                'quantity' => $quantity,
                'note' => CreditGrant::DEFAULT_QUANTITY === $quantity ? null : 'Rest of an earlier pass',
            ]);
        }
        $voidAt = $joinedAt->modify('+10 minutes');
        $voided = CreditGrantFactory::new()
            ->afterInstantiate(static function (CreditGrant $grant) use ($voidAt): void {
                $grant->void($voidAt);
            })
            ->create(['enrollment' => $tangoEnrollments[1], 'now' => $joinedAt->modify('+5 minutes'), 'note' => 'Entered twice']);

        // Salsa: 7 past lessons, the fourth of them cancelled; Tango: 6 past lessons. Both: 4 future.
        $salsaPast = $this->lessons($salsa, $now, 7, 4, cancelledIndex: 3);
        $tangoPast = $this->lessons($tango, $now, 6, 4, cancelledIndex: null);

        $marked = $this->attend($salsaPast, $salsaEnrollments, alwaysPresent: [1, 2], leftAfter: []);
        $this->attend($tangoPast, $tangoEnrollments, alwaysPresent: [], leftAfter: [3 => 3]);

        // The latest completed lesson had a check-in window, now closed.
        $latest = $this->latest([...$salsaPast, ...$tangoPast]);
        $openedAt = $latest->getEndsAtUtc()->modify('-5 minutes');
        $closedAt = $openedAt->modify('+40 minutes');
        CheckInWindowFactory::new()
            ->afterInstantiate(static function (CheckInWindow $window) use ($closedAt): void {
                $window->close($closedAt);
            })
            ->create([
                'lessonSession' => $latest,
                'tokenHash' => hash('sha256', random_bytes(32)),
                'openedAt' => $openedAt,
                'expiresAt' => $openedAt->modify('+60 minutes'),
            ]);

        AuditEventFactory::createOne([
            'teacher' => $teacher,
            'actorType' => ActorType::Teacher,
            'action' => 'credit_grant.created',
            'entityType' => 'credit_grant',
            'entityId' => $grants[0]->getId(),
            'occurredAt' => $grants[0]->getGrantedAt(),
            'details' => ['quantity' => $grants[0]->getQuantity()],
        ]);
        AuditEventFactory::createOne([
            'teacher' => $teacher,
            'actorType' => ActorType::Teacher,
            'action' => 'credit_grant.voided',
            'entityType' => 'credit_grant',
            'entityId' => $voided->getId(),
            'occurredAt' => $voidAt,
            'details' => ['quantity' => $voided->getQuantity()],
        ]);
        AuditEventFactory::createOne([
            'teacher' => $teacher,
            'actorType' => ActorType::Teacher,
            'action' => 'attendance.marked',
            'entityType' => 'attendance',
            'entityId' => $marked['id'],
            'occurredAt' => $marked['at'],
            'details' => ['status' => AttendanceStatus::Absent->value],
        ]);
    }

    /**
     * Weekly lessons in the course's zone, converted to UTC by the entity. Today is skipped.
     *
     * @return list<LessonSession> the past lessons, oldest first
     */
    private function lessons(Course $course, \DateTimeImmutable $now, int $past, int $future, ?int $cancelledIndex): array
    {
        $zone = new \DateTimeZone($course->getTimezone());
        $today = $now->setTimezone($zone)->setTime(0, 0);
        $day = self::WEEKDAYS[$course->getWeekday()];
        $time = $course->getLocalStartTime();
        $at = static fn (\DateTimeImmutable $date): \DateTimeImmutable => $date->setTime((int) $time->format('H'), (int) $time->format('i'));

        $lastPast = $today->modify('last '.$day);
        $lessons = [];
        for ($i = $past - 1; $i >= 0; --$i) {
            $start = $at($lastPast->modify(\sprintf('-%d weeks', $i)));
            $factory = LessonSessionFactory::new();
            $factory = \count($lessons) === $cancelledIndex
                ? $factory->afterInstantiate(static function (LessonSession $lesson): void {
                    $lesson->cancel($lesson->getStartsAtUtc()->modify('-1 day'));
                })
                : $factory->completed();
            $lessons[] = $factory->create([
                'course' => $course,
                'startsAtUtc' => $start,
                'endsAtUtc' => $start->modify(\sprintf('+%d minutes', $course->getDurationMinutes())),
            ]);
        }

        $firstFuture = $today->modify('next '.$day);
        for ($i = 0; $i < $future; ++$i) {
            $start = $at($firstFuture->modify(\sprintf('+%d weeks', $i)));
            LessonSessionFactory::createOne([
                'course' => $course,
                'startsAtUtc' => $start,
                'endsAtUtc' => $start->modify(\sprintf('+%d minutes', $course->getDurationMinutes())),
            ]);
        }

        return $lessons;
    }

    /**
     * Marks every enrollment at every completed lesson: present, or absent on a fixed pattern.
     * Enrollments in $alwaysPresent are never absent; $leftAfter maps an enrollment index to the
     * number of lessons it was marked at before leaving. The latest lesson's present marks come
     * from the student QR flow.
     *
     * @param list<LessonSession> $lessons
     * @param list<Enrollment>    $enrollments
     * @param list<int>           $alwaysPresent
     * @param array<int, int>     $leftAfter
     *
     * @return array{id: Uuid, at: \DateTimeImmutable} the last teacher-marked absence
     */
    private function attend(array $lessons, array $enrollments, array $alwaysPresent, array $leftAfter): array
    {
        $completed = array_values(array_filter($lessons, static fn (LessonSession $l): bool => !$l->isCancelled()));
        $lastIndex = \count($completed) - 1;
        $marked = null;

        foreach ($completed as $l => $lesson) {
            foreach ($enrollments as $e => $enrollment) {
                if (isset($leftAfter[$e]) && $l >= $leftAfter[$e]) {
                    continue;
                }
                $present = \in_array($e, $alwaysPresent, true) || 0 !== ($l + $e) % 4;
                $source = $present && $l === $lastIndex ? AttendanceSource::StudentQr : AttendanceSource::Teacher;
                $at = $lesson->getEndsAtUtc()->modify($present ? '-2 minutes' : '+15 minutes');
                $attendance = AttendanceFactory::createOne([
                    'lessonSession' => $lesson,
                    'student' => $enrollment->getStudent(),
                    'status' => $present ? AttendanceStatus::Present : AttendanceStatus::Absent,
                    'source' => $source,
                    'now' => $at,
                ]);
                if (!$present) {
                    $marked = ['id' => $attendance->getId(), 'at' => $at];
                }
            }
        }

        if (null === $marked) {
            throw new \LogicException('The demo pattern must contain at least one absence.');
        }

        return $marked;
    }

    /**
     * @param list<LessonSession> $lessons
     */
    private function latest(array $lessons): LessonSession
    {
        $completed = array_filter($lessons, static fn (LessonSession $l): bool => !$l->isCancelled());
        usort($completed, static fn (LessonSession $a, LessonSession $b): int => $a->getStartsAtUtc() <=> $b->getStartsAtUtc());
        $latest = end($completed);
        if (false === $latest) {
            throw new \LogicException('The demo story needs at least one completed lesson.');
        }

        return $latest;
    }
}
