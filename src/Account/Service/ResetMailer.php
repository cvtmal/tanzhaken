<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\Teacher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The password-reset mail: plain text only, from MAILER_FROM under the brand name, queued on
 * DeferredMailer so it leaves after the response.
 */
final class ResetMailer
{
    public function __construct(
        private readonly DeferredMailer $mailer,
        private readonly TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'APP_BRAND_NAME')]
        private readonly string $brandName,
    ) {
    }

    public function send(Teacher $teacher, string $resetUrl, int $validMinutes): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from, $this->brandName))
            ->to(new Address($teacher->getEmail(), $teacher->getDisplayName()))
            ->subject($this->translator->trans('email.password_reset.subject', ['brand' => $this->brandName], 'account'))
            ->textTemplate('account/email/password_reset.txt.twig')
            ->context([
                'display_name' => $teacher->getDisplayName(),
                'reset_url' => $resetUrl,
                'minutes' => $validMinutes,
            ]);

        $this->mailer->queue($email);
    }
}
