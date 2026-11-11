<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Entity\Teacher;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the registration form (A2) submits. Lengths follow the `teacher` columns; the password
 * rule is "8 characters or more" (A2), capped at 4096 against hashing very long input.
 * Messages are keys in the `validators` domain, prefixed with the module (`account.`).
 */
final class RegistrationData
{
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 4096;

    #[Assert\NotBlank(message: 'account.register.name.blank')]
    #[Assert\Length(max: Teacher::NAME_MAX, maxMessage: 'account.register.name.too_long')]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'account.register.email.blank')]
    #[Assert\Email(message: 'account.register.email.invalid')]
    #[Assert\Length(max: Teacher::EMAIL_MAX, maxMessage: 'account.register.email.too_long')]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'account.register.password.blank')]
    #[Assert\Length(
        min: self::PASSWORD_MIN,
        max: self::PASSWORD_MAX,
        minMessage: 'account.register.password.too_short',
        maxMessage: 'account.register.password.too_long',
    )]
    public ?string $password = null;
}
