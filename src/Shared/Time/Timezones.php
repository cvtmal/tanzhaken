<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * IANA time zones (D10). A teacher and every course carry one; Europe/Zurich is the default.
 */
final class Timezones
{
    public const DEFAULT = 'Europe/Zurich';

    public static function isValid(string $tz): bool
    {
        return '' !== $tz && \in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
