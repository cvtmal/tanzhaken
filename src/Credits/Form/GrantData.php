<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Entity\CreditGrant;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the add-credits form (CRD-1, E2) submits: an amount (`10`, `5` or `other` with
 * `otherAmount`, PD6), the effective date in the teacher's zone (PD5), an optional note and the
 * return target (`from`, PD4; read with ReturnTarget::fromQuery()). Extended by CorrectData.
 */
class GrantData
{
    public const AMOUNT_FULL = '10';
    public const AMOUNT_HALF = '5';
    public const AMOUNT_OTHER = 'other';
    /** Form-only cap per grant against typos (PD6); the ledger only requires ≥ 1. */
    public const OTHER_MAX = 100;

    #[Assert\NotBlank(message: 'credits.grant.amount.blank')]
    #[Assert\Choice(choices: [self::AMOUNT_FULL, self::AMOUNT_HALF, self::AMOUNT_OTHER], message: 'credits.grant.amount.blank')]
    public ?string $amount = self::AMOUNT_FULL;

    /** Used only when `amount` is `other`. */
    public ?int $otherAmount = null;

    /** Only `Y-m-d` counts: the local date in the teacher's zone (the form's model zone is UTC). */
    #[Assert\NotNull(message: 'credits.grant.date.blank')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\Length(max: CreditGrant::NOTE_MAX, maxMessage: 'credits.grant.note.too_long')]
    public ?string $note = null;

    public ?string $from = null;

    #[Assert\Callback]
    public function validateOtherAmount(ExecutionContextInterface $context): void
    {
        if (self::AMOUNT_OTHER !== $this->amount) {
            return;
        }
        if (null === $this->otherAmount || $this->otherAmount < 1 || $this->otherAmount > self::OTHER_MAX) {
            $context->buildViolation('credits.grant.other_amount.out_of_range')
                ->setParameter('{max}', (string) self::OTHER_MAX)
                ->atPath('otherAmount')
                ->addViolation();
        }
    }

    /**
     * The number of lessons to grant: 10, 5 or `otherAmount` (0 when it is missing; call only on
     * a valid form).
     */
    public function quantity(): int
    {
        return match ($this->amount) {
            self::AMOUNT_HALF => 5,
            self::AMOUNT_OTHER => $this->otherAmount ?? 0,
            default => CreditGrant::DEFAULT_QUANTITY,
        };
    }

    /**
     * The `grantedAt` for CreditLedger (PD5): null for today in `$timezone` (the ledger uses now),
     * otherwise 00:00 of the chosen day in `$timezone`, in UTC. A future day stays in the future,
     * so the ledger refuses it (`grantedInFuture`).
     */
    public function effectiveAt(\DateTimeImmutable $now, string $timezone): ?\DateTimeImmutable
    {
        if (null === $this->date) {
            return null;
        }
        $zone = new \DateTimeZone($timezone);
        $day = $this->date->format('Y-m-d');
        if ($day === $now->setTimezone($zone)->format('Y-m-d')) {
            return null;
        }

        return new \DateTimeImmutable($day.' 00:00:00', $zone)->setTimezone(new \DateTimeZone('UTC'));
    }
}
