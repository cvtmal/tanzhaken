<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Entity\Course;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\LessonSessionRepository;
use App\Courses\Service\LessonScheduler;
use App\Courses\Service\ScheduleGenerator;
use App\Shared\Audit\AuditLogger;
use App\Tests\Factory\CourseFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds the LessonScheduler for integration tests and reads lessons and audit rows back by DBAL,
 * so they can be checked after a refusal. Use in a KernelTestCase.
 */
trait BuildsLessonScheduler
{
    use DbalRows;

    private static function scheduler(): LessonScheduler
    {
        // LessonScheduler has no consumer until #10, so the compiled container has removed it (and
        // AuditLogger); build it from the container's EntityManager, repositories and connection and
        // the framework clock (which follows mockTime()).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $courses = $container->get(CourseRepository::class);
        $lessons = $container->get(LessonSessionRepository::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(CourseRepository::class, $courses);
        self::assertInstanceOf(LessonSessionRepository::class, $lessons);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new LessonScheduler($em, $courses, $lessons, new ScheduleGenerator(), new AuditLogger(self::connection(), $clock), $clock);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * A Zurich course on Tuesdays at 19:00 for 60 minutes, starting on Tuesday 2026-03-03.
     *
     * @param array<string, mixed> $attributes
     */
    private static function zurichCourse(array $attributes = [], bool $archived = false): Course
    {
        $factory = $archived ? CourseFactory::new()->archived() : CourseFactory::new();

        return $factory->create($attributes + [
            'timezone' => 'Europe/Zurich',
            'weekday' => 2,
            'localStartTime' => new \DateTimeImmutable('1970-01-01 19:00:00'),
            'durationMinutes' => 60,
            'startsOn' => new \DateTimeImmutable('2026-03-03'),
        ]);
    }

    /**
     * The course's lessons by DBAL, in start order: UTC `Y-m-d H:i:s` starts and ends, and status.
     *
     * @return list<array{startsAtUtc: string, endsAtUtc: string, status: string}>
     */
    private static function lessonRows(Course $course): array
    {
        /** @var list<array{startsAtUtc: string, endsAtUtc: string, status: string}> $rows */
        $rows = self::connection()->fetchAllAssociative(
            'SELECT starts_at_utc AS "startsAtUtc", ends_at_utc AS "endsAtUtc", status FROM lesson_session WHERE course_id = ? ORDER BY starts_at_utc',
            [$course->getId()],
            [UuidType::NAME],
        );

        return $rows;
    }

    /**
     * The course's lesson starts by DBAL, UTC `Y-m-d H:i:s`, in order.
     *
     * @return list<string>
     */
    private static function lessonStarts(Course $course): array
    {
        return array_column(self::lessonRows($course), 'startsAtUtc');
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

    /**
     * Asserts that the call throws an exception of the expected class and message.
     */
    private static function assertRefused(\Throwable $expected, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $actual) {
            self::assertSame($expected::class, $actual::class, $actual->getMessage());
            self::assertSame($expected->getMessage(), $actual->getMessage());

            return;
        }

        self::fail(\sprintf('Expected %s: %s', $expected::class, $expected->getMessage()));
    }
}
