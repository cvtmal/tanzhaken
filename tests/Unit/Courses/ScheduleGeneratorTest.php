<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Service\Occurrence;
use App\Courses\Service\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

final class ScheduleGeneratorTest extends TestCase
{
    public function test_journey_h_keeps_19_00_zurich_across_both_dst_changes(): void
    {
        $course = self::course(7, '19:00', 90, '2026-03-22');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-03-22'), self::date('2026-11-01'));

        self::assertCount(33, $occurrences);
        self::assertSame('2026-03-22', $occurrences[0]->localDate);
        self::assertSame('2026-11-01', $occurrences[32]->localDate);

        $zurich = new \DateTimeZone('Europe/Zurich');
        $previous = null;
        foreach ($occurrences as $occurrence) {
            self::assertSame('UTC', $occurrence->startsAtUtc->getTimezone()->getName());
            self::assertSame(
                $occurrence->localDate.' 19:00',
                $occurrence->startsAtUtc->setTimezone($zurich)->format('Y-m-d H:i'),
            );
            if (null !== $previous) {
                self::assertSame(7, self::date($previous->localDate)->diff(self::date($occurrence->localDate))->days);
            }
            $previous = $occurrence;
        }

        $utcStarts = self::utcStartsByLocalDate($occurrences);
        self::assertSame('2026-03-22 18:00:00', $utcStarts['2026-03-22']);
        self::assertSame('2026-03-29 17:00:00', $utcStarts['2026-03-29']);
        self::assertSame('2026-10-18 17:00:00', $utcStarts['2026-10-18']);
        self::assertSame('2026-10-25 18:00:00', $utcStarts['2026-10-25']);
        self::assertSame('2026-11-01 18:00:00', $utcStarts['2026-11-01']);
    }

    public function test_a_time_in_the_spring_gap_uses_the_offset_before_the_gap(): void
    {
        $course = self::course(7, '02:30', 60, '2026-03-29');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-03-29'), self::date('2026-03-29'));

        self::assertCount(1, $occurrences);
        self::assertSame('2026-03-29 01:30:00', $occurrences[0]->startsAtUtc->format('Y-m-d H:i:s'));
    }

    public function test_a_repeated_autumn_time_takes_its_first_occurrence(): void
    {
        $course = self::course(7, '02:30', 60, '2026-10-25');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-10-25'), self::date('2026-10-25'));

        self::assertCount(1, $occurrences);
        self::assertSame('2026-10-25 00:30:00', $occurrences[0]->startsAtUtc->format('Y-m-d H:i:s'));
    }

    public function test_the_end_is_the_start_plus_the_duration_in_elapsed_time(): void
    {
        $course = self::course(7, '01:30', 90, '2026-03-29');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-03-29'), self::date('2026-03-29'));

        self::assertCount(1, $occurrences);
        self::assertSame('2026-03-29 00:30:00', $occurrences[0]->startsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-29 02:00:00', $occurrences[0]->endsAtUtc->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $occurrences[0]->endsAtUtc->getTimezone()->getName());
    }

    public function test_the_first_occurrence_is_aligned_to_the_course_weekday(): void
    {
        // 2026-09-07 is a Monday; weekday 3 is Wednesday.
        $course = self::course(3, '19:30', 90, '2026-09-07');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-09-07'), self::date('2026-09-30'));

        self::assertSame('2026-09-09', $occurrences[0]->localDate);
        self::assertSame('2026-09-09 17:30:00', $occurrences[0]->startsAtUtc->format('Y-m-d H:i:s'));
    }

    public function test_ends_on_is_inclusive_and_stops_generation(): void
    {
        // 2026-09-02 and 2026-09-30 are Wednesdays.
        $course = self::course(3, '19:30', 90, '2026-09-02', '2026-09-30');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-09-02'), self::date('2026-12-31'));

        self::assertSame(
            ['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23', '2026-09-30'],
            array_map(static fn (Occurrence $o): string => $o->localDate, $occurrences),
        );
    }

    public function test_from_before_starts_on_is_clipped(): void
    {
        $course = self::course(3, '19:30', 90, '2026-09-02');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-08-01'), self::date('2026-09-16'));

        self::assertSame(
            ['2026-09-02', '2026-09-09', '2026-09-16'],
            array_map(static fn (Occurrence $o): string => $o->localDate, $occurrences),
        );
    }

    public function test_from_after_until_gives_no_occurrence(): void
    {
        $course = self::course(3, '19:30', 90, '2026-09-02');

        self::assertSame([], (new ScheduleGenerator())->occurrences($course, self::date('2026-10-10'), self::date('2026-10-01')));
    }

    public function test_a_zone_without_dst_keeps_the_same_utc_time(): void
    {
        $course = self::course(7, '19:00', 90, '2026-03-22', null, 'UTC');

        $occurrences = (new ScheduleGenerator())->occurrences($course, self::date('2026-03-22'), self::date('2026-11-01'));

        self::assertCount(33, $occurrences);
        foreach ($occurrences as $occurrence) {
            self::assertSame($occurrence->localDate.' 19:00:00', $occurrence->startsAtUtc->format('Y-m-d H:i:s'));
        }
    }

    public function test_utc_start_ignores_the_zone_of_the_given_wall_clock(): void
    {
        $start = (new ScheduleGenerator())->utcStart(
            'Europe/Zurich',
            new \DateTimeImmutable('2026-07-01 19:00', new \DateTimeZone('America/New_York')),
        );

        self::assertSame('UTC', $start->getTimezone()->getName());
        self::assertSame('2026-07-01 17:00:00', $start->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<Occurrence> $occurrences
     *
     * @return array<string, string>
     */
    private static function utcStartsByLocalDate(array $occurrences): array
    {
        $starts = [];
        foreach ($occurrences as $occurrence) {
            $starts[$occurrence->localDate] = $occurrence->startsAtUtc->format('Y-m-d H:i:s');
        }

        return $starts;
    }

    private static function date(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd, new \DateTimeZone('UTC'));
    }

    private static function course(
        int $weekday,
        string $localStartTime,
        int $durationMinutes,
        string $startsOn,
        ?string $endsOn = null,
        string $timezone = 'Europe/Zurich',
    ): Course {
        $now = new \DateTimeImmutable('2026-11-10 10:00:00');

        return new Course(
            new Teacher('Ann Example', 'ann@example.com', 'hash', $now),
            'Salsa',
            $timezone,
            $weekday,
            new \DateTimeImmutable($localStartTime),
            $durationMinutes,
            new \DateTimeImmutable($startsOn),
            $now,
            null === $endsOn ? null : new \DateTimeImmutable($endsOn),
        );
    }
}
