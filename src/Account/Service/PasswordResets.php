<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidResetLink;
use App\Account\Exception\InvalidTeacher;
use App\Account\Repository\PasswordResetRequestRepository;
use App\Account\Repository\TeacherRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgot/reset password (ACC-3). A teacher has at most one pending link (a new request replaces
 * the old one); a link is valid for LIFETIME_MINUTES and dies when used. request() answers the
 * same way for every input, so it cannot be used to probe accounts. Refusals are thrown before
 * the transaction opens (S2, D-027).
 */
final class PasswordResets
{
    public const LIFETIME_MINUTES = 60;

    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly PasswordResetRequestRepository $requests,
        private readonly EntityManagerInterface $em,
        private readonly ResetTokens $tokens,
        private readonly PasswordResetThrottle $throttle,
        private readonly ResetLinkGenerator $links,
        private readonly ResetMailer $mailer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Mails a reset link when the email belongs to a teacher and its limit allows it; otherwise
     * does nothing. Never throws for unknown, malformed or limited input. No audit event (R10).
     * Purges every expired link of every teacher on each request the email limit allows (F-m2).
     */
    public function request(string $email): void
    {
        try {
            $email = Teacher::normaliseEmail($email);
        } catch (InvalidTeacher) {
            return;
        }
        if (null !== $this->throttle->allowEmail($email)) {
            return;
        }
        $this->requests->deleteExpired($this->clock->now());
        $teacher = $this->teachers->findOneByEmail($email);
        if (null === $teacher) {
            return;
        }

        $token = $this->tokens->issue();
        $now = $this->clock->now();
        $request = new PasswordResetRequest($teacher, $token->hash, $now, $now->modify(\sprintf('+%d minutes', self::LIFETIME_MINUTES)));

        $this->em->wrapInTransaction(function () use ($teacher, $request): void {
            $this->requests->deleteForTeacher($teacher);
            $this->em->persist($request);
            $this->em->flush();
        });

        $this->mailer->send($teacher, $this->links->forToken($token->plain), self::LIFETIME_MINUTES);
    }

    /**
     * The pending request for this plain token, or null when the token is malformed, unknown or expired.
     */
    public function findValid(string $token): ?PasswordResetRequest
    {
        if (!$this->tokens->isWellFormed($token)) {
            return null;
        }
        $request = $this->requests->findOneByTokenHash($this->tokens->hash($token));
        if (null === $request || $request->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $request;
    }

    /**
     * Expects a new password the form has validated. Deletes every pending link of the teacher.
     *
     * @throws InvalidResetLink the token is malformed, unknown, expired or already used
     */
    public function reset(string $token, string $newPassword): void
    {
        $request = $this->findValid($token);
        if (null === $request) {
            throw InvalidResetLink::create();
        }

        $teacher = $request->getTeacher();
        $hash = $this->hasher->hashPassword($teacher, $newPassword);

        $this->em->wrapInTransaction(function () use ($teacher, $hash): void {
            $teacher->changePasswordHash($hash);
            $this->requests->deleteForTeacher($teacher);
            $this->em->flush();
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'teacher.password_reset', 'teacher', $teacher->getId());
        });
    }
}
