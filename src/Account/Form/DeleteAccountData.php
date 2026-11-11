<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the delete card of /account submits: the teacher's password, checked against the
 * logged-in teacher's hash. The password is the confirmation (research D3).
 */
final class DeleteAccountData
{
    #[Assert\NotBlank(message: 'account.delete.password.blank')]
    #[UserPassword(message: 'account.delete.password.wrong')]
    public ?string $password = null;
}
