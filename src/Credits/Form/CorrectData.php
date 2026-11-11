<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Entity\CreditGrant;
use App\Credits\Service\CreditLedger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the correction form (CRD-5, S9 "void plus a new grant") submits: the grant fields of
 * GrantData (the corrected entry) plus an optional reason for the void.
 */
final class CorrectData extends GrantData
{
    #[Assert\Length(max: CreditLedger::REASON_MAX, maxMessage: 'credits.void.reason.too_long')]
    public ?string $reason = null;

    /**
     * Prefills the form with the grant being corrected: its amount (`10`, `5`, or `other` with the
     * quantity), its effective date in `$timezone` and its note.
     */
    public static function fromGrant(CreditGrant $grant, string $timezone): self
    {
        $data = new self();
        $quantity = $grant->getQuantity();
        $data->amount = match ($quantity) {
            CreditGrant::DEFAULT_QUANTITY => self::AMOUNT_FULL,
            5 => self::AMOUNT_HALF,
            default => self::AMOUNT_OTHER,
        };
        $data->otherAmount = self::AMOUNT_OTHER === $data->amount ? $quantity : null;
        // The form's model zone is UTC (PHP's default): carry the local date, not the instant.
        $data->date = new \DateTimeImmutable($grant->getGrantedAt()->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d'));
        $data->note = $grant->getNote();

        return $data;
    }
}
