<?php

declare(strict_types=1);

namespace App\Account\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends queued mails on kernel.terminate, after the response has gone out, so "forgot password"
 * answers equally fast for known and unknown emails (no timing oracle). A transport failure is
 * logged, not rethrown: the response is already sent.
 */
final class DeferredMailer implements EventSubscriberInterface, ResetInterface
{
    /** @var list<Email> */
    private array $queue = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'sendQueued'];
    }

    public function queue(Email $email): void
    {
        $this->queue[] = $email;
    }

    public function sendQueued(): void
    {
        $queued = $this->queue;
        $this->queue = [];

        foreach ($queued as $email) {
            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Deferred mail could not be sent: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
    }
}
