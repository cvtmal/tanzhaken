<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\CheckIn\ReadModel\CheckInLookup;
use App\CheckIn\ReadModel\CheckInRoster;
use App\CheckIn\ReadModel\RosterEntry;
use App\Courses\Entity\LessonStatus;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInLookupAndRosterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    public function test_unknown_hash_is_null(): void
    {
        self::bootKernel();
        CheckInWindowFactory::createOne();

        self::assertNull(self::lookup()->byTokenHash(hash('sha256', 'unknown')));
    }

    public function test_open_window_returns_every_fact(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 18:55:00');
        $teacher = TeacherFactory::createOne(['name' => 'Mara Lindqvist']);
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa basics', 'timezone' => 'Europe/Berlin']);
        $lesson = LessonSessionFactory::createOne([
            'course' => $course,
            'startsAtUtc' => new \DateTimeImmutable('2026-03-10 18:00:00', new \DateTimeZone('UTC')),
            'endsAtUtc' => new \DateTimeImmutable('2026-03-10 19:00:00', new \DateTimeZone('UTC')),
        ]);
        $hash = hash('sha256', 'open');
        $window = CheckInWindowFactory::createOne(['lessonSession' => $lesson, 'tokenHash' => $hash]);

        $facts = self::lookup()->byTokenHash($hash);

        self::assertNotNull($facts);
        self::assertTrue($facts->windowId->equals($window->getId()));
        self::assertSame('2026-03-10 18:55:00 UTC', $facts->openedAt->format('Y-m-d H:i:s T'));
        self::assertSame('2026-03-10 19:55:00 UTC', $facts->expiresAt->format('Y-m-d H:i:s T'));
        self::assertNull($facts->closedAt);
        self::assertTrue($facts->lessonId->equals($lesson->getId()));
        self::assertSame(LessonStatus::Scheduled, $facts->lessonStatus);
        self::assertSame('2026-03-10 18:00:00 UTC', $facts->lessonStartsAtUtc->format('Y-m-d H:i:s T'));
        self::assertSame('2026-03-10 19:00:00 UTC', $facts->lessonEndsAtUtc->format('Y-m-d H:i:s T'));
        self::assertTrue($facts->courseId->equals($course->getId()));
        self::assertSame('Salsa basics', $facts->courseName);
        self::assertSame('Europe/Berlin', $facts->courseTimezone);
        self::assertFalse($facts->courseArchived);
        self::assertTrue($facts->teacherId->equals($teacher->getId()));
        self::assertSame('Mara Lindqvist', $facts->teacherName);
    }

    public function test_closed_window_of_cancelled_lesson_in_archived_course(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 18:55:00');
        $course = CourseFactory::new()->archived()->create();
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $course]);
        $hash = hash('sha256', 'closed');
        CheckInWindowFactory::new()->closed()->create(['lessonSession' => $lesson, 'tokenHash' => $hash]);

        $facts = self::lookup()->byTokenHash($hash);

        self::assertNotNull($facts);
        self::assertSame('2026-03-10 18:55:00 UTC', $facts->closedAt?->format('Y-m-d H:i:s T'));
        self::assertSame(LessonStatus::Cancelled, $facts->lessonStatus);
        self::assertTrue($facts->courseArchived);
    }

    public function test_roster_holds_active_enrollments_of_non_archived_students_by_joined_at(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne();
        $teacher = $course->getTeacher();
        // Created out of joined_at order, so the order cannot come from the UUIDv7 ids.
        $later = EnrollmentFactory::createOne([
            'course' => $course,
            'student' => StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Zoe', 'lastName' => 'Roth']),
            'now' => new \DateTimeImmutable('2026-02-01 10:00:00', new \DateTimeZone('UTC')),
        ]);
        $earlier = EnrollmentFactory::createOne([
            'course' => $course,
            'student' => StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Anna', 'lastName' => 'Müller']),
            'now' => new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC')),
            'publicCheckinLabel' => 'Annie',
        ]);
        EnrollmentFactory::new()->left()->create(['course' => $course]);
        // D-035: still enrolled, but archived: excluded.
        EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::new()->archived()->create(['teacher' => $teacher])]);
        EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher])]);

        $roster = self::roster()->forCourse($course->getId());

        self::assertSame(
            [$earlier->getId()->toRfc4122(), $later->getId()->toRfc4122()],
            array_map(static fn (RosterEntry $entry): string => $entry->enrollmentId->toRfc4122(), $roster),
        );
        self::assertTrue($roster[0]->studentId->equals($earlier->getStudent()->getId()));
        self::assertSame('Anna', $roster[0]->firstName);
        self::assertSame('Müller', $roster[0]->lastName);
        self::assertSame('Annie', $roster[0]->publicCheckinLabel);
        self::assertSame('2026-01-01 10:00:00 UTC', $roster[0]->joinedAt->format('Y-m-d H:i:s T'));
        self::assertNull($roster[1]->publicCheckinLabel);
    }

    public function test_roster_of_course_without_enrollments_is_empty(): void
    {
        self::bootKernel();
        EnrollmentFactory::createOne();

        self::assertSame([], self::roster()->forCourse(CourseFactory::createOne()->getId()));
    }

    private static function lookup(): CheckInLookup
    {
        // Built by hand until a consumer (#14) makes the read model reachable in the container (D-026).
        return new CheckInLookup(self::connection());
    }

    private static function roster(): CheckInRoster
    {
        return new CheckInRoster(self::connection());
    }
}
