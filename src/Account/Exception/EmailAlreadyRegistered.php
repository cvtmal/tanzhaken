<?php

declare(strict_types=1);

namespace App\Account\Exception;

/**
 * Registration refused: a teacher with this (normalised) email exists. The controller shows it as
 * a form error on the email field.
 */
final class EmailAlreadyRegistered extends \DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(\sprintf('A teacher with the email "%s" is already registered.', $email));
    }
}
