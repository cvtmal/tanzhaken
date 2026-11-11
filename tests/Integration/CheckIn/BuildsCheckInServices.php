<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\Account\Entity\Teacher;
use App\Account\Repository\TeacherRepository;
use App\Attendance\Service\CheckInRecorder;
use App\CheckIn\ReadModel\CheckInLookup;
use App\CheckIn\ReadModel\CheckInRoster;
use App\CheckIn\Repository\CheckInWindowRepository;
use App\CheckIn\Service\CheckInTokens;
use App\CheckIn\Service\CheckInWindows;
use App\CheckIn\Service\PublicCheckIn;
use App\CheckIn\Service\RosterLabeller;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\LessonSessionRepository;
use App\Courses\Service\LessonScheduler;
use App\Courses\Service\ScheduleGenerator;
use App\Credits\ReadModel\Balances;
use App\Shared\Audit\AuditLogger;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the CheckIn services for integration tests. They have no consumer until #14 and #16, so
 * the compiled container has removed them (D-026); they are built from the container's
 * EntityManager, repositories and connection and the framework clock (which follows mockTime()).
 * Use in a KernelTestCase.
 */
trait BuildsCheckInServices
{
    use DbalRows;

    private static function windows(): CheckInWindows
    {
        $container = self::getContainer();
        $courses = $container->get(CourseRepository::class);
        $lessons = $container->get(LessonSessionRepository::class);
        $windows = $container->get(CheckInWindowRepository::class);
        self::assertInstanceOf(CourseRepository::class, $courses);
        self::assertInstanceOf(LessonSessionRepository::class, $lessons);
        self::assertInstanceOf(CheckInWindowRepository::class, $windows);
        $em = self::entityManager();
        $clock = self::clock();
        $audit = new AuditLogger(self::connection(), $clock);

        return new CheckInWindows(
            $em,
            $lessons,
            $windows,
            new CheckInLookup(self::connection()),
            new CheckInTokens(),
            new LessonScheduler($em, $courses, $lessons, new ScheduleGenerator(), $audit, $clock),
            $audit,
            $clock,
        );
    }

    private static function publicCheckIn(): PublicCheckIn
    {
        $teachers = self::getContainer()->get(TeacherRepository::class);
        self::assertInstanceOf(TeacherRepository::class, $teachers);

        return new PublicCheckIn(
            self::windows(),
            new CheckInRoster(self::connection()),
            new RosterLabeller(),
            new CheckInRecorder(self::connection(), self::clock()),
            new Balances(self::connection()),
            $teachers,
        );
    }

    private static function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private static function clock(): ClockInterface
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    /**
     * A teacher's scheduled lesson in a course with two active enrollments; the attributes go to
     * the teacher, the course and the first enrollment's student.
     *
     * @param array<string, mixed> $teacher
     * @param array<string, mixed> $course
     * @param array<string, mixed> $student
     *
     * @return array{Teacher, LessonSession, Enrollment, Enrollment}
     */
    private static function openLesson(array $teacher = [], array $course = [], array $student = []): array
    {
        $owner = TeacherFactory::createOne($teacher);
        $lessonCourse = CourseFactory::createOne(['teacher' => $owner] + $course);
        $lesson = LessonSessionFactory::createOne(['course' => $lessonCourse]);

        return [
            $owner,
            $lesson,
            EnrollmentFactory::createOne(['course' => $lessonCourse, 'student' => StudentFactory::createOne(['teacher' => $owner] + $student)]),
            EnrollmentFactory::createOne(['course' => $lessonCourse]),
        ];
    }

    /**
     * The decoded details of every audit row with this action, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function auditDetails(string $action): array
    {
        $details = [];
        foreach (self::connection()->fetchFirstColumn('SELECT details_json FROM audit_event WHERE action = ? ORDER BY occurred_at, id', [$action]) as $json) {
            $decoded = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /* @var array<string, mixed> $decoded */
            $details[] = $decoded;
        }

        return $details;
    }
}
