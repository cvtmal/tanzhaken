<?php

declare(strict_types=1);

namespace App\Students\Exception;

final class InvalidStudent extends \DomainException
{
    public static function emptyFirstName(): self
    {
        return new self('The student first name must not be empty.');
    }

    public static function emptyLastName(): self
    {
        return new self('The student last name must not be empty.');
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self(\sprintf('The student %s must not be longer than %d characters.', $field, $max));
    }

    public static function alreadyArchived(): self
    {
        return new self('The student is already archived.');
    }
}
