<?php

declare(strict_types=1);

namespace App\Account\Exception;

final class InvalidTeacher extends \DomainException
{
    public static function emptyName(): self
    {
        return new self('The teacher name must not be empty.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self(\sprintf('The teacher name must not be longer than %d characters.', $max));
    }

    public static function emptyEmail(): self
    {
        return new self('The teacher email must not be empty.');
    }

    public static function emailTooLong(int $max): self
    {
        return new self(\sprintf('The teacher email must not be longer than %d characters.', $max));
    }

    public static function invalidTimezone(string $timezone): self
    {
        return new self(\sprintf('"%s" is not a valid time zone.', $timezone));
    }
}
