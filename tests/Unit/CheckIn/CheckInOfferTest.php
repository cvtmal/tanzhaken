<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\Service\CheckInOffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Open check-in" is offered from 15 minutes before the lesson starts (P3), and stays offered after.
 */
final class CheckInOfferTest extends TestCase
{
    private const STARTS_AT = '2026-11-10 18:00:00';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function moments(): iterable
    {
        yield '16 minutes before' => ['2026-11-10 17:44:00', false];
        yield 'one second before the lead time' => ['2026-11-10 17:44:59', false];
        yield 'exactly 15 minutes before' => ['2026-11-10 17:45:00', true];
        yield 'at the start' => ['2026-11-10 18:00:00', true];
        yield 'after the start' => ['2026-11-10 18:30:00', true];
        yield 'days later' => ['2026-11-14 09:00:00', true];
    }

    #[DataProvider('moments')]
    public function test_it_is_offerable_from_the_lead_time_on(string $now, bool $offerable): void
    {
        $utc = new \DateTimeZone('UTC');

        self::assertSame($offerable, (new CheckInOffer())->isOfferable(new \DateTimeImmutable(self::STARTS_AT, $utc), new \DateTimeImmutable($now, $utc)));
    }
}
