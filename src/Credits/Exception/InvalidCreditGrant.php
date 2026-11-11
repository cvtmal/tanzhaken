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

    public static function grantedInFuture(): self
    {
        return new self('A credit grant cannot take effect in the future.');
    }

    public static function enrollmentNotActive(): self
    {
        return new self('Credits can only be granted to an active enrollment of an active course and student.');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self(\sprintf('The reason must not be longer than %d characters.', $max));
    }
}
