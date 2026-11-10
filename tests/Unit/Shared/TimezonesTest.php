<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Time\Timezones;
use PHPUnit\Framework\TestCase;

final class TimezonesTest extends TestCase
{
    public function test_the_default_is_a_valid_zone(): void
    {
        self::assertSame('Europe/Zurich', Timezones::DEFAULT);
        self::assertTrue(Timezones::isValid(Timezones::DEFAULT));
    }

    public function test_it_rejects_an_unknown_zone(): void
    {
        self::assertFalse(Timezones::isValid('Mars/Base'));
    }

    public function test_it_rejects_an_empty_string(): void
    {
        self::assertFalse(Timezones::isValid(''));
    }
}
