<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\Account\Entity\Teacher;
use App\CheckIn\Entity\CheckInWindow;
use App\CheckIn\Exception\InvalidCheckInWindow;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CheckInWindowTest extends TestCase
{
    #[TestWith(['abc'])]
    #[TestWith(['ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789'])]
    #[TestWith(['zzcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789'])]
    public function test_it_refuses_a_bad_token_hash(string $hash): void
    {
        $this->expectException(InvalidCheckInWindow::class);

        new CheckInWindow(self::lesson(), $hash, self::openedAt(), self::openedAt()->modify('+15 minutes'));
    }

    public function test_it_refuses_an_expiry_not_after_opening(): void
    {
        $this->expectException(InvalidCheckInWindow::class);

        new CheckInWindow(self::lesson(), self::hash(), self::openedAt(), self::openedAt());
    }

    public function test_it_is_active_until_expiry(): void
    {
        $lesson = self::lesson();
        $window = new CheckInWindow($lesson, self::hash(), self::openedAt(), self::openedAt()->modify('+15 minutes'));

        self::assertTrue($window->isActiveAt(self::openedAt()->modify('+14 minutes')));
        self::assertFalse($window->isActiveAt(self::openedAt()->modify('+15 minutes')));
        self::assertSame(self::hash(), $window->getTokenHash());
        self::assertTrue($lesson->ownerId()->equals($window->ownerId()));
    }

    public function test_it_is_inactive_after_close(): void
    {
        $window = new CheckInWindow(self::lesson(), self::hash(), self::openedAt(), self::openedAt()->modify('+15 minutes'));
        $window->close(self::openedAt()->modify('+5 minutes'));

        self::assertFalse($window->isActiveAt(self::openedAt()->modify('+6 minutes')));
        self::assertEquals(self::openedAt()->modify('+5 minutes'), $window->getClosedAt());

        $this->expectException(InvalidCheckInWindow::class);
        $window->close(self::openedAt()->modify('+7 minutes'));
    }

    private static function hash(): string
    {
        return hash('sha256', 'token');
    }

    private static function openedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-11 19:30:00');
    }

    private static function lesson(): LessonSession
    {
        $now = new \DateTimeImmutable('2026-11-10 10:00:00');
        $course = new Course(new Teacher('Ann Example', 'ann@example.com', 'hash', $now), 'Salsa', 'Europe/Zurich', 3, new \DateTimeImmutable('19:30'), 60, new \DateTimeImmutable('2026-09-01'), $now);

        return new LessonSession($course, new \DateTimeImmutable('2026-11-11 18:30:00'), new \DateTimeImmutable('2026-11-11 19:30:00'));
    }
}
