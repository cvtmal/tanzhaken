<?php

declare(strict_types=1);

namespace App\Attendance\Service;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\ReadModel\CourseRegisterExport;
use App\Courses\Entity\Course;
use App\Credits\ReadModel\Balances;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The course register as a CSV download (#15, D4): one row per lesson and student
 * (date, time and zone in the course's zone, student, status), an empty line, then a balance
 * summary per enrollment straight from Balances (R5, never recomputed). Every word comes from the
 * `attendance` catalogue; numbers are int cells so a negative balance stays a number (D5).
 */
final class CourseRegisterCsv
{
    private const DOMAIN = 'attendance';

    public function __construct(
        private readonly CourseRegisterExport $register,
        private readonly Balances $balances,
        private readonly CsvEncoder $encoder,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(Teacher $teacher, Course $course): string
    {
        return $this->encoder->encode($this->rows($teacher, $course));
    }

    /**
     * `register-<slug>-<date>.csv`, the date being today in the course's zone (D6).
     */
    public function filename(Course $course): string
    {
        $slug = (string) (new AsciiSlugger('en'))->slug($course->getName())->lower();
        $date = $this->clock->now()->setTimezone(new \DateTimeZone($course->getTimezone()))->format('Y-m-d');

        return '' === $slug
            ? $this->trans('export.filename_no_slug', ['date' => $date])
            : $this->trans('export.filename', ['slug' => $slug, 'date' => $date]);
    }

    /**
     * @return iterable<list<string|int|null>>
     */
    private function rows(Teacher $teacher, Course $course): iterable
    {
        $timezone = $course->getTimezone();
        $zone = new \DateTimeZone($timezone);

        yield [
            $this->trans('export.column.date'),
            $this->trans('export.column.time'),
            $this->trans('export.column.timezone'),
            $this->trans('export.column.student'),
            $this->trans('export.column.status'),
        ];
        foreach ($this->register->forCourse($teacher, $course->getId(), $this->clock->now()) as $row) {
            $local = $row->startsAtUtc->setTimezone($zone);
            yield [
                $local->format('Y-m-d'),
                $local->format('H:i'),
                $timezone,
                $this->name($row->firstName, $row->lastName),
                $this->trans(match ($row->status) {
                    AttendanceStatus::Present => 'export.status.present',
                    AttendanceStatus::Absent => 'export.status.absent',
                    null => 'export.status.not_marked',
                }),
            ];
        }

        yield [];
        yield [$this->trans('export.summary.title')];
        yield [
            $this->trans('export.summary.column.student'),
            $this->trans('export.summary.column.credits'),
            $this->trans('export.summary.column.used'),
            $this->trans('export.summary.column.balance'),
            $this->trans('export.summary.column.state'),
            $this->trans('export.summary.column.enrollment'),
        ];
        foreach ($this->balances->forCourse($teacher, $course->getId()) as $balance) {
            yield [
                $this->name($balance->studentFirstName, $balance->studentLastName),
                $balance->credits,
                $balance->used,
                $balance->balance,
                $this->trans('export.state.'.$balance->state->value),
                $this->trans($balance->active ? 'export.enrollment.active' : 'export.enrollment.left'),
            ];
        }
    }

    private function name(string $first, string $last): string
    {
        return $this->trans('export.student_name', ['first' => $first, 'last' => $last]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN);
    }
}
