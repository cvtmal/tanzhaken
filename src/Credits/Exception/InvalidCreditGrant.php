<?php

declare(strict_types=1);

namespace App\Credits\Exception;

final class InvalidCreditGrant extends \DomainException
{
    public static function quantityNotPositive(int $quantity): self
    {
        return new self(\sprintf('A credit grant must add at least one lesson, %d given.', $quantity));
    }

    public static function noteTooLong(int $max): self
    {
        return new self(\sprintf('The credit grant note must not be longer than %d characters.', $max));
    }

    public static function alreadyVoided(): self
    {
        return new self('The credit grant is already voided.');
    }
}
