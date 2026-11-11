<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * A reset link that is malformed, unknown, expired or already used. One message for all four,
 * so the page reveals nothing about which case it was.
 */
final class InvalidResetLink extends \DomainException
{
    public static function create(): self
    {
        return new self('The password reset link is invalid or has expired.');
    }
}
