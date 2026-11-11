<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Entity\Teacher;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the forgot-password form (ACC-3) submits: the email the teacher registered with.
 * Messages are keys in the `validators` domain, prefixed with the module (`account.`).
 */
final class ForgotPasswordData
{
    #[Assert\NotBlank(message: 'account.forgot.email.blank')]
    #[Assert\Email(message: 'account.forgot.email.invalid')]
    #[Assert\Length(max: Teacher::EMAIL_MAX, maxMessage: 'account.forgot.email.too_long')]
    public ?string $email = null;
}
