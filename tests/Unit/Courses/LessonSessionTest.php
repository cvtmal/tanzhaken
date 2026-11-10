<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Courses\Exception\InvalidCourse;
use PHPUnit\Framework\TestCase;

final class LessonSessionTest extends TestCase
{
    public function test_it_stores_utc_whole_seconds(): void
    {
        $zurich = new \DateTimeZone('Europe/Zurich');
        $lesson = new LessonSession(
            self::course(),
            new \DateTimeImmutable('2026-11-11 19:30:00.654321', $zurich),
            new \DateTimeImmutable('2026-11-11 21:00:00.123456', $zurich),
        );

        self::assertSame('UTC', $lesson->getStartsAtUtc()->getTimezone()->getName());
        self::assertSame('2026-11-11 18:30:00.000000', $lesson->getStartsAtUtc()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-11-11 20:00:00.000000', $lesson->getEndsAtUtc()->format('Y-m-d H:i:s.u'));
        self::assertSame(LessonStatus::Scheduled, $lesson->getStatus());
        self::assertNull($lesson->getCancelledAt());
    }

    public function test_it_refuses_an_end_not_after_the_start(): void
    {
        $this->expectException(InvalidCourse::class);

        new LessonSession(self::course(), new \DateTimeImmutable('2026-11-11 18:30:00'), new \DateTimeImmutable('2026-11-11 18:30:00.9'));
    }

    public function test_cancel_sets_status_and_time(): void
    {
        $lesson = self::lesson();
        $now = new \DateTimeImmutable('2026-11-10 12:00:00');

        $lesson->cancel($now);

        self::assertTrue($lesson->isCancelled());
        self::assertSame(LessonStatus::Cancelled, $lesson->getStatus());
        self::assertEquals($now, $lesson->getCancelledAt());
    }

    public function test_complete_and_owner(): void
    {
        $lesson = self::lesson();
        $lesson->complete();

        self::assertSame(LessonStatus::Completed, $lesson->getStatus());
        self::assertTrue($lesson->getCourse()->ownerId()->equals($lesson->ownerId()));
    }

    public function test_a_cancelled_lesson_cannot_complete(): void
    {
        $lesson = self::lesson();
        $lesson->cancel(new \DateTimeImmutable('2026-11-10 12:00:00'));

        $this->expectException(InvalidCourse::class);

        $lesson->complete();
    }

    private static function lesson(): LessonSession
    {
        return new LessonSession(self::course(), new \DateTimeImmutable('2026-11-11 18:30:00'), new \DateTimeImmutable('2026-11-11 20:00:00'));
    }

    private static function course(): Course
    {
        $now = new \DateTimeImmutable('2026-11-10 10:00:00');

        return new Course(new Teacher('Ann Example', 'ann@example.com', 'hash', $now), 'Salsa', 'Europe/Zurich', 3, new \DateTimeImmutable('19:30'), 90, new \DateTimeImmutable('2026-09-01'), $now);
    }
}
