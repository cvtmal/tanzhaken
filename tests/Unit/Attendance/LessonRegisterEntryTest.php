<?php

declare(strict_types=1);

namespace App\Tests\Unit\Attendance;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\ReadModel\LessonRegisterEntry;
use App\Attendance\ReadModel\LessonRegisterRow;
use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\ReadModel\RenewalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LessonRegisterEntryTest extends TestCase
{
    /**
     * @return iterable<string, array{?AttendanceStatus, ?int, ?int, ?int}>
     */
    public static function previews(): iterable
    {
        // status, balance now, balance if present, balance if absent
        yield 'not marked' => [null, 5, 4, 5];
        yield 'present' => [AttendanceStatus::Present, 4, 4, 5];
        yield 'absent' => [AttendanceStatus::Absent, 5, 4, 5];
        yield 'negative' => [null, 0, -1, 0];
        yield 'no balance' => [null, null, null, null];
    }

    #[DataProvider('previews')]
    public function test_it_previews_the_balance_after_a_mark(?AttendanceStatus $status, ?int $balance, ?int $ifPresent, ?int $ifAbsent): void
    {
        $row = self::row(status: $status);
        $entry = new LessonRegisterEntry($row, null === $balance ? null : self::balance($row, $balance));

        self::assertSame($balance, $entry->balance());
        self::assertSame($ifPresent, $entry->balanceIfPresent());
        self::assertSame($ifAbsent, $entry->balanceIfAbsent());
        self::assertSame(null !== $status, $entry->isMarked());
        self::assertSame(AttendanceStatus::Present === $status, $entry->isPresent());
    }

    public function test_display_name_is_first_name_and_last_initial(): void
    {
        self::assertSame('Alex M.', (new LessonRegisterEntry(self::row('Alex', 'Martin'), null))->displayName());
        self::assertSame('Deniz Ö.', (new LessonRegisterEntry(self::row('Deniz', 'Öz'), null))->displayName());
    }

    public function test_anchor_is_the_student_id(): void
    {
        $row = self::row();

        self::assertSame('student-'.$row->studentId->toRfc4122(), (new LessonRegisterEntry($row, null))->anchor());
    }

    public function test_from_register_merges_balances_by_enrollment_and_keeps_the_order(): void
    {
        $withBalance = self::row('Alex', 'Martin');
        $missingKey = self::row('Bea', 'Neri');
        $left = self::row('Cem', 'Ok', enrolled: false);
        $balance = self::balance($withBalance, 3);
        $enrollmentId = $withBalance->enrollmentId;
        self::assertNotNull($enrollmentId);

        $entries = LessonRegisterEntry::fromRegister([$withBalance, $missingKey, $left], [$enrollmentId->toRfc4122() => $balance]);

        self::assertCount(3, $entries);
        self::assertSame($withBalance, $entries[0]->row);
        self::assertSame($balance, $entries[0]->balance);
        self::assertSame($missingKey, $entries[1]->row);
        self::assertNull($entries[1]->balance);
        self::assertSame($left, $entries[2]->row);
        self::assertNull($entries[2]->balance);
    }

    private static function row(string $firstName = 'Alex', string $lastName = 'Martin', ?AttendanceStatus $status = null, bool $enrolled = true): LessonRegisterRow
    {
        return new LessonRegisterRow(
            Uuid::v7(),
            $enrolled ? Uuid::v7() : null,
            $firstName,
            $lastName,
            null,
            false,
            $enrolled,
            $status,
            null === $status ? null : AttendanceSource::Teacher,
            null === $status ? null : new \DateTimeImmutable('2026-11-10 19:05:00'),
        );
    }

    private static function balance(LessonRegisterRow $row, int $balance): EnrollmentBalance
    {
        $enrollmentId = $row->enrollmentId;
        self::assertNotNull($enrollmentId);

        return new EnrollmentBalance(
            $enrollmentId,
            Uuid::v7(),
            'Salsa',
            $row->studentId,
            $row->firstName,
            $row->lastName,
            true,
            10,
            10 - $balance,
            $balance,
            RenewalState::fromBalance($balance),
        );
    }
}
