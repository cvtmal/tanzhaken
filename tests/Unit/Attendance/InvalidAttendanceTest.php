<?php

declare(strict_types=1);

namespace App\Tests\Unit\Attendance;

use App\Attendance\Exception\InvalidAttendance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidAttendanceTest extends TestCase
{
    /**
     * @return iterable<string, array{InvalidAttendance, string, array<string, scalar>}>
     */
    public static function refusals(): iterable
    {
        yield 'studentOfAnotherTeacher' => [InvalidAttendance::studentOfAnotherTeacher(), 'attendance.error.student_of_another_teacher', []];
        yield 'lessonCancelled' => [InvalidAttendance::lessonCancelled(), 'attendance.error.lesson_cancelled', []];
        yield 'studentNotEnrolled' => [InvalidAttendance::studentNotEnrolled(), 'attendance.error.student_not_enrolled', []];
        yield 'notMarked' => [InvalidAttendance::notMarked(), 'attendance.error.not_marked', []];
        yield 'reasonTooLong' => [InvalidAttendance::reasonTooLong(255), 'attendance.error.reason_too_long', ['max' => 255]];
        yield 'changedConcurrently' => [InvalidAttendance::changedConcurrently(new \RuntimeException('race')), 'attendance.error.changed_concurrently', []];
        yield 'notEligibleForCheckIn' => [InvalidAttendance::notEligibleForCheckIn(), 'attendance.error.not_eligible_for_check_in', []];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    #[DataProvider('refusals')]
    public function test_every_refusal_has_its_translation_key_and_parameters(InvalidAttendance $refusal, string $key, array $parameters): void
    {
        self::assertSame($key, $refusal->translationKey());
        self::assertSame($parameters, $refusal->translationParameters());
        self::assertNotSame('', $refusal->getMessage());
    }

    public function test_every_public_factory_is_covered(): void
    {
        $factories = [];
        foreach ((new \ReflectionClass(InvalidAttendance::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($method->isPublic()) {
                $factories[] = $method->getName();
            }
        }
        $covered = array_keys(iterator_to_array(self::refusals()));
        sort($factories);
        sort($covered);

        self::assertSame($factories, $covered);
    }

    public function test_changed_concurrently_chains_the_previous_exception(): void
    {
        $previous = new \RuntimeException('unique key');

        self::assertSame($previous, InvalidAttendance::changedConcurrently($previous)->getPrevious());
        self::assertNull(InvalidAttendance::lessonCancelled()->getPrevious());
    }
}
