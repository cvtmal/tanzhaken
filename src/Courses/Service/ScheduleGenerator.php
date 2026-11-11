<?php

declare(strict_types=1);

namespace App\Courses\Service;

use App\Courses\Entity\Course;

/**
 * Turns a course's weekly rule into lesson times (D1).
 *
 * A "local date" is a \DateTimeImmutable of which only format('Y-m-d') counts. Dates are
 * advanced as calendar days on a UTC midnight value, never by hours, so a DST change does
 * not shift them.
 */
final class ScheduleGenerator
{
    /**
     * The single local-to-UTC conversion: the date and wall-clock time of $localWallClock,
     * read in $timezone (its own zone is ignored). A time in the spring gap takes the offset
     * before the gap; a repeated autumn time takes its first occurrence.
     */
    public function utcStart(string $timezone, \DateTimeImmutable $localWallClock): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($localWallClock->format('Y-m-d'), new \DateTimeZone($timezone)))
            ->setTime((int) $localWallClock->format('H'), (int) $localWallClock->format('i'), (int) $localWallClock->format('s'))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Every occurrence of the course between the two local dates (both inclusive), clipped to
     * the course's startsOn and endsOn, in chronological order.
     *
     * @return list<Occurrence>
     */
    public function occurrences(Course $course, \DateTimeImmutable $fromLocalDate, \DateTimeImmutable $untilLocalDate): array
    {
        $from = max($fromLocalDate->format('Y-m-d'), $course->getStartsOn()->format('Y-m-d'));
        $until = $untilLocalDate->format('Y-m-d');
        $endsOn = $course->getEndsOn();
        if (null !== $endsOn) {
            $until = min($until, $endsOn->format('Y-m-d'));
        }
        if ($from > $until) {
            return [];
        }

        $utc = new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable($from, $utc);
        $offset = ($course->getWeekday() - (int) $date->format('N') + 7) % 7;
        $date = $date->modify('+'.$offset.' days');
        $time = $course->getLocalStartTime()->format('H:i:s');

        $occurrences = [];
        while ($date->format('Y-m-d') <= $until) {
            $localDate = $date->format('Y-m-d');
            $start = $this->utcStart($course->getTimezone(), new \DateTimeImmutable($localDate.' '.$time, $utc));
            $occurrences[] = new Occurrence(
                $start,
                $start->modify('+'.$course->getDurationMinutes().' minutes'),
                $localDate,
            );
            $date = $date->modify('+7 days');
        }

        return $occurrences;
    }
}
