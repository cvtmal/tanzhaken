<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Exception\InvalidCourse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseTest extends TestCase
{
    public function test_it_keeps_its_schedule_and_owner(): void
    {
        $teacher = new Teacher('Ann Example', 'ann@example.com', 'hash', self::now());
        $course = self::course($teacher, location: ' Studio 2 ', description: '');

        self::assertTrue($teacher->getId()->equals($course->ownerId()));
        self::assertSame('Salsa Beginners', $course->getName());
        self::assertSame('Europe/Zurich', $course->getTimezone());
        self::assertSame(3, $course->getWeekday());
        self::assertSame('19:30', $course->getLocalStartTime()->format('H:i'));
        self::assertSame(90, $course->getDurationMinutes());
        self::assertSame('Studio 2', $course->getLocation());
        self::assertNull($course->getDescription());
        self::assertNull($course->getEndsOn());
        self::assertSame('7', $course->getId()->toRfc4122()[14]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidCourses(): iterable
    {
        yield 'empty name' => [['name' => '  ']];
        yield 'invalid timezone' => [['timezone' => 'Mars/Base']];
        yield 'weekday 0' => [['weekday' => 0]];
        yield 'weekday 8' => [['weekday' => 8]];
        yield 'duration 0' => [['durationMinutes' => 0]];
        yield 'duration 1441' => [['durationMinutes' => 1441]];
        yield 'ends before start' => [['endsOn' => new \DateTimeImmutable('2026-08-31')]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidCourses')]
    public function test_it_rejects_an_invalid_course(array $arguments): void
    {
        $this->expectException(InvalidCourse::class);

        self::course(...$arguments);
    }

    public function test_a_course_of_one_day_is_valid(): void
    {
        $course = self::course(endsOn: new \DateTimeImmutable('2026-09-01'));

        self::assertSame('2026-09-01', $course->getEndsOn()?->format('Y-m-d'));
    }

    public function test_it_rejects_an_invalid_schedule_change(): void
    {
        $course = self::course();

        $this->expectException(InvalidCourse::class);

        $course->changeSchedule('Europe/Zurich', 9, new \DateTimeImmutable('19:30'), 60, new \DateTimeImmutable('2026-09-01'), null);
    }

    public function test_it_archives_once(): void
    {
        $course = self::course();
        $course->archive(self::now());

        self::assertTrue($course->isArchived());
        self::assertEquals(self::now(), $course->getArchivedAt());

        $this->expectException(InvalidCourse::class);
        $course->archive(self::now());
    }

    private static function course(
        ?Teacher $teacher = null,
        string $name = 'Salsa Beginners',
        string $timezone = 'Europe/Zurich',
        int $weekday = 3,
        int $durationMinutes = 90,
        ?\DateTimeImmutable $endsOn = null,
        ?string $description = null,
        ?string $location = null,
    ): Course {
        return new Course(
            $teacher ?? new Teacher('Ann Example', 'ann@example.com', 'hash', self::now()),
            $name,
            $timezone,
            $weekday,
            new \DateTimeImmutable('1970-01-01 19:30:00'),
            $durationMinutes,
            new \DateTimeImmutable('2026-09-01'),
            self::now(),
            $endsOn,
            $description,
            $location,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 10:00:00');
    }
}
