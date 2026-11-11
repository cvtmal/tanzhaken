<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Courses\Entity\LessonStatus;
use App\Dashboard\ReadModel\DashboardLesson;
use App\Dashboard\ReadModel\TodayBoard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TodayBoardTest extends TestCase
{
    private const NOW = '2026-03-14 18:00:00';

    public function test_hero_is_the_latest_lesson_within_the_lead(): void
    {
        $morning = self::lesson('09:00');
        $ended = self::lesson('16:00');
        $started = self::lesson('17:30');
        $later = self::lesson('20:00');
        $board = self::board([$morning, $ended, $started, $later]);

        self::assertSame($started, $board->hero());
        self::assertSame([$morning, $ended, $later], $board->others());
    }

    public function test_hero_is_the_next_lesson_when_none_has_started(): void
    {
        $next = self::lesson('19:00');
        $later = self::lesson('20:30');
        $board = self::board([$next, $later]);

        self::assertSame($next, $board->hero());
        self::assertSame([$later], $board->others());
        self::assertFalse($board->canOpenCheckIn($next));
    }

    public function test_a_cancelled_lesson_is_never_the_hero(): void
    {
        $cancelledNow = self::lesson('17:45', LessonStatus::Cancelled);
        $ended = self::lesson('15:00');
        $board = self::board([$ended, $cancelledNow]);

        self::assertSame($ended, $board->hero());
        self::assertSame([$cancelledNow], $board->others());
        self::assertFalse($board->canOpenCheckIn($cancelledNow));
    }

    public function test_no_hero_when_today_is_empty_or_all_cancelled(): void
    {
        self::assertNull(self::board([])->hero());
        self::assertSame([], self::board([])->others());

        $cancelled = self::lesson('19:00', LessonStatus::Cancelled);
        $board = self::board([$cancelled]);
        self::assertNull($board->hero());
        self::assertSame([$cancelled], $board->others());
    }

    public function test_check_in_lead_is_fifteen_minutes(): void
    {
        $in14 = self::lesson('18:14');
        $in16 = self::lesson('18:16');
        $board = self::board([$in14, $in16]);

        self::assertTrue($board->canOpenCheckIn($in14));
        self::assertFalse($board->canOpenCheckIn($in16));
        self::assertSame($in14, $board->hero());

        $in15 = self::lesson('18:15');
        self::assertTrue(self::board([$in15])->canOpenCheckIn($in15));
    }

    public function test_upcoming_lessons_cannot_open_a_check_in(): void
    {
        $upcoming = self::lesson('17:50', day: '2026-03-15');
        $board = new TodayBoard([], [$upcoming], new \DateTimeImmutable('2026-03-15 18:00:00'), true);

        self::assertTrue($upcoming->canOpenCheckIn($board->now));
        self::assertFalse($board->canOpenCheckIn($upcoming));
    }

    public function test_today_course_ids_skip_cancelled_lessons(): void
    {
        $salsa = Uuid::v7();
        $tango = Uuid::v7();
        $board = self::board([
            self::lesson('10:00', courseId: $salsa),
            self::lesson('12:00', LessonStatus::Cancelled, courseId: $tango),
            self::lesson('19:00', courseId: $salsa),
        ]);

        self::assertSame([$salsa->toRfc4122() => true], $board->todayCourseIds());
    }

    public function test_minutes_since_end_and_until_start(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $ended = self::lesson('16:58');
        $running = self::lesson('17:30');
        $future = self::lesson('18:13');

        self::assertSame(2, $ended->minutesSinceEnd($now));
        self::assertNull($ended->minutesUntilStart($now));
        self::assertFalse($ended->isInProgress($now));

        self::assertNull($running->minutesSinceEnd($now));
        self::assertNull($running->minutesUntilStart($now));
        self::assertTrue($running->isInProgress($now));

        self::assertNull($future->minutesSinceEnd($now));
        self::assertSame(13, $future->minutesUntilStart($now));
        self::assertFalse($future->isInProgress($now));
        self::assertSame(1, self::lesson('18:00:30')->minutesUntilStart($now));
        self::assertSame(0, self::lesson('17:00')->minutesSinceEnd($now));
    }

    /**
     * @param list<DashboardLesson> $today
     */
    private static function board(array $today): TodayBoard
    {
        return new TodayBoard($today, [], new \DateTimeImmutable(self::NOW), true);
    }

    /** A 60-minute lesson starting at $time (UTC) on $day. */
    private static function lesson(
        string $time,
        LessonStatus $status = LessonStatus::Scheduled,
        string $day = '2026-03-14',
        ?Uuid $courseId = null,
    ): DashboardLesson {
        $startsAt = new \DateTimeImmutable($day.' '.$time);

        return new DashboardLesson(
            lessonId: Uuid::v7(),
            courseId: $courseId ?? Uuid::v7(),
            courseName: 'Salsa',
            location: 'Studio 2',
            timezone: 'UTC',
            durationMinutes: 60,
            startsAtUtc: $startsAt,
            endsAtUtc: $startsAt->modify('+60 minutes'),
            status: $status,
            studentCount: 12,
            checkInOpen: false,
        );
    }
}
