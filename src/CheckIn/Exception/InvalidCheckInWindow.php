<?php

declare(strict_types=1);

namespace App\CheckIn\Exception;

final class InvalidCheckInWindow extends \DomainException
{
    public static function invalidTokenHash(): self
    {
        return new self('The token hash must be a lower-case hex SHA-256 (64 characters).');
    }

    public static function expiresBeforeOpening(): self
    {
        return new self('A check-in window must expire after it opens.');
    }

    public static function alreadyClosed(): self
    {
        return new self('The check-in window is already closed.');
    }
}
