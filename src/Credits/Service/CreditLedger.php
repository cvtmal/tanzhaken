<?php

declare(strict_types=1);

namespace App\Credits\Service;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Enrollment;
use App\Courses\Repository\EnrollmentRepository;
use App\Credits\Entity\CreditGrant;
use App\Credits\Exception\CreditNotFound;
use App\Credits\Exception\InvalidCreditGrant;
use App\Credits\Repository\CreditGrantRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes to the credit ledger (CRD-1, CRD-5, S9): grant, void, correct (void + new grant), each
 * with its audit events. Every refusal (CreditNotFound → 404, InvalidCreditGrant → form error) is
 * thrown before the transaction opens: ORM 3 closes the EntityManager on any exception inside
 * wrapInTransaction(), which would break a form re-render in the same request (S2).
 */
final class CreditLedger
{
    public const REASON_MAX = 255;
    private const ENTITY_TYPE = 'credit_grant';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnrollmentRepository $enrollments,
        private readonly CreditGrantRepository $grants,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Adds lessons to one of the teacher's active enrollments. `$grantedAt` (the effective date)
     * defaults to now, is stored in UTC and may not lie in the future.
     *
     * @throws CreditNotFound     the enrollment is unknown or another teacher's
     * @throws InvalidCreditGrant quantity < 1, note too long, date in the future, inactive enrollment
     */
    public function grant(
        Teacher $teacher,
        Uuid $enrollmentId,
        int $quantity = CreditGrant::DEFAULT_QUANTITY,
        ?\DateTimeImmutable $grantedAt = null,
        ?string $note = null,
    ): CreditGrant {
        $enrollment = $this->enrollments->findOwned($teacher, $enrollmentId)
            ?? throw CreditNotFound::enrollment($enrollmentId);
        $grant = $this->newGrant($enrollment, $quantity, $grantedAt, $note);

        $this->em->wrapInTransaction(function () use ($teacher, $grant): void {
            $this->em->persist($grant);
            $this->recordCreated($teacher, $grant, []);
        });

        return $grant;
    }

    /**
     * Voids one of the teacher's grants; the row stays. Allowed on a left enrollment and on an
     * archived course or student, so a mistake can always be undone.
     *
     * @throws CreditNotFound     the grant is unknown or another teacher's
     * @throws InvalidCreditGrant already voided, reason too long
     */
    public function void(Teacher $teacher, Uuid $grantId, ?string $reason = null): void
    {
        $grant = $this->voidableGrant($teacher, $grantId);
        $reason = $this->cleanReason($reason);
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($teacher, $grant, $reason, $now): void {
            $grant->void($now);
            $this->recordVoided($teacher, $grant, $reason, []);
        });
    }

    /**
     * Corrects a grant (S9): voids it and grants `$quantity` to the same enrollment, in one
     * transaction with two linked audit events. Returns the new grant.
     *
     * @throws CreditNotFound     the grant is unknown or another teacher's
     * @throws InvalidCreditGrant as grant() and void()
     */
    public function correct(
        Teacher $teacher,
        Uuid $grantId,
        int $quantity,
        ?\DateTimeImmutable $grantedAt = null,
        ?string $note = null,
        ?string $reason = null,
    ): CreditGrant {
        $old = $this->voidableGrant($teacher, $grantId);
        $reason = $this->cleanReason($reason);
        $new = $this->newGrant($old->getEnrollment(), $quantity, $grantedAt, $note);
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($teacher, $old, $new, $reason, $now): void {
            $old->void($now);
            $this->em->persist($new);
            $this->recordVoided($teacher, $old, $reason, ['replaced_by' => $new->getId()->toRfc4122()]);
            $this->recordCreated($teacher, $new, ['corrects' => $old->getId()->toRfc4122()]);
        });

        return $new;
    }

    private function newGrant(Enrollment $enrollment, int $quantity, ?\DateTimeImmutable $grantedAt, ?string $note): CreditGrant
    {
        if (!$enrollment->isActive() || $enrollment->getCourse()->isArchived() || $enrollment->getStudent()->isArchived()) {
            throw InvalidCreditGrant::enrollmentNotActive();
        }

        $now = $this->clock->now();
        $effectiveAt = ($grantedAt ?? $now)->setTimezone(new \DateTimeZone('UTC'));
        if ($effectiveAt > $now) {
            throw InvalidCreditGrant::grantedInFuture();
        }

        // The entity refuses a quantity below 1 and a note that is too long.
        return new CreditGrant($enrollment, $effectiveAt, $quantity, $note);
    }

    private function voidableGrant(Teacher $teacher, Uuid $grantId): CreditGrant
    {
        $grant = $this->grants->findOwned($teacher, $grantId) ?? throw CreditNotFound::grant($grantId);
        if ($grant->isVoided()) {
            throw InvalidCreditGrant::alreadyVoided();
        }

        return $grant;
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw InvalidCreditGrant::reasonTooLong(self::REASON_MAX);
        }

        return '' === $reason ? null : $reason;
    }

    /**
     * @param array<string, string> $extra
     */
    private function recordCreated(Teacher $teacher, CreditGrant $grant, array $extra): void
    {
        $this->audit->record($teacher->getId(), ActorType::Teacher, 'credit_grant.created', self::ENTITY_TYPE, $grant->getId(), [
            'quantity' => $grant->getQuantity(),
            'granted_at' => $grant->getGrantedAt()->format('Y-m-d H:i:s'),
        ] + $extra);
    }

    /**
     * @param array<string, string> $extra
     */
    private function recordVoided(Teacher $teacher, CreditGrant $grant, ?string $reason, array $extra): void
    {
        $details = ['quantity' => $grant->getQuantity()];
        if (null !== $reason) {
            $details['reason'] = $reason;
        }

        $this->audit->record($teacher->getId(), ActorType::Teacher, 'credit_grant.voided', self::ENTITY_TYPE, $grant->getId(), $details + $extra);
    }
}
