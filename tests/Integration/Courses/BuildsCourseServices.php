<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\EnrollmentRepository;
use App\Courses\Service\CourseCatalog;
use App\Courses\Service\Roster;
use App\Shared\Audit\AuditLogger;
use App\Students\Repository\StudentRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Builds CourseCatalog and Roster for integration tests. Until a controller uses them (Phase 4
 * of #10) the compiled container removes both, like LessonScheduler and AuditLogger, so they are
 * built from the container's EntityManager, repositories and the framework clock (which follows
 * mockTime()). Use in a KernelTestCase.
 */
trait BuildsCourseServices
{
    use BuildsLessonScheduler;

    private static function courseCatalog(): CourseCatalog
    {
        return new CourseCatalog(self::entityManager(), self::service(CourseRepository::class), self::scheduler(), self::auditLogger(), self::clock());
    }

    private static function roster(): Roster
    {
        return new Roster(
            self::entityManager(),
            self::service(CourseRepository::class),
            self::service(EnrollmentRepository::class),
            self::service(StudentRepository::class),
            self::auditLogger(),
            self::clock(),
        );
    }

    private static function auditLogger(): AuditLogger
    {
        return new AuditLogger(self::connection(), self::clock());
    }

    private static function clock(): ClockInterface
    {
        return self::service(ClockInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
