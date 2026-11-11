<?php

declare(strict_types=1);

namespace App\Credits\ReadModel;

/**
 * The renewal state of a balance (S9): ≤ 0 "Renewal needed", 1–LOW_MAX "Low", otherwise neutral.
 */
enum RenewalState: string
{
    case RenewalNeeded = 'renewal_needed';
    case Low = 'low';
    case Ok = 'ok';

    public const LOW_MAX = 2;

    public static function fromBalance(int $balance): self
    {
        return match (true) {
            $balance <= 0 => self::RenewalNeeded,
            $balance <= self::LOW_MAX => self::Low,
            default => self::Ok,
        };
    }
}
