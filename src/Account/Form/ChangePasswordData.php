<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the password card of /account submits: the current password (checked against the logged-in
 * teacher's hash) and the new one, with the registration rule (decision P6).
 */
final class ChangePasswordData
{
    #[Assert\NotBlank(message: 'account.settings.current_password.blank')]
    #[UserPassword(message: 'account.settings.current_password.wrong')]
    public ?string $currentPassword = null;

    #[Assert\NotBlank(message: 'account.register.password.blank')]
    #[Assert\Length(
        min: RegistrationData::PASSWORD_MIN,
        max: RegistrationData::PASSWORD_MAX,
        minMessage: 'account.register.password.too_short',
        maxMessage: 'account.register.password.too_long',
    )]
    public ?string $newPassword = null;
}
