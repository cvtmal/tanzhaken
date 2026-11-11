<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the reset-password form submits: the new password. Same rule and messages as registration
 * (decision P6): 8 characters or more, capped at 4096.
 */
final class ResetPasswordData
{
    #[Assert\NotBlank(message: 'account.register.password.blank')]
    #[Assert\Length(
        min: RegistrationData::PASSWORD_MIN,
        max: RegistrationData::PASSWORD_MAX,
        minMessage: 'account.register.password.too_short',
        maxMessage: 'account.register.password.too_long',
    )]
    public ?string $password = null;
}
