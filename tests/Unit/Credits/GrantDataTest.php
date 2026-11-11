<?php

declare(strict_types=1);

namespace App\Tests\Unit\Credits;

use App\Credits\Form\GrantData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantDataTest extends TestCase
{
    private const ZONE = 'Europe/Zurich';

    /**
     * @return iterable<string, array{string, ?int, int}>
     */
    public static function amounts(): iterable
    {
        yield 'full pass' => [GrantData::AMOUNT_FULL, null, 10];
        yield 'half pass' => [GrantData::AMOUNT_HALF, 7, 5];
        yield 'other' => [GrantData::AMOUNT_OTHER, 7, 7];
        yield 'other without number' => [GrantData::AMOUNT_OTHER, null, 0];
    }

    #[DataProvider('amounts')]
    public function test_quantity(string $amount, ?int $other, int $expected): void
    {
        $data = new GrantData();
        $data->amount = $amount;
        $data->otherAmount = $other;

        self::assertSame($expected, $data->quantity());
    }

    public function test_default_is_a_full_pass(): void
    {
        self::assertSame(10, new GrantData()->quantity());
    }

    public function test_today_in_teacher_zone_gives_null(): void
    {
        // 23:30 UTC on 14 March is already 15 March in Zurich.
        $now = new \DateTimeImmutable('2026-03-14 23:30:00', new \DateTimeZone('UTC'));
        $data = new GrantData();
        $data->date = new \DateTimeImmutable('2026-03-15');

        self::assertNull($data->effectiveAt($now, self::ZONE));
    }

    public function test_earlier_date_is_midnight_local_in_utc(): void
    {
        $now = new \DateTimeImmutable('2026-03-14 09:00:00', new \DateTimeZone('UTC'));
        $data = new GrantData();
        $data->date = new \DateTimeImmutable('2026-03-10');

        $at = $data->effectiveAt($now, self::ZONE);

        self::assertNotNull($at);
        self::assertSame('2026-03-09 23:00:00', $at->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $at->getTimezone()->getName());
    }

    public function test_summer_time_offset(): void
    {
        $now = new \DateTimeImmutable('2026-08-14 09:00:00', new \DateTimeZone('UTC'));
        $data = new GrantData();
        $data->date = new \DateTimeImmutable('2026-07-01');

        self::assertSame('2026-06-30 22:00:00', $data->effectiveAt($now, self::ZONE)?->format('Y-m-d H:i:s'));
    }

    public function test_future_date_stays_in_the_future(): void
    {
        $now = new \DateTimeImmutable('2026-03-14 09:00:00', new \DateTimeZone('UTC'));
        $data = new GrantData();
        $data->date = new \DateTimeImmutable('2026-03-15');

        self::assertGreaterThan($now, $data->effectiveAt($now, self::ZONE));
    }
}
