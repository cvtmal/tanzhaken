<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Service\CreditLedger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the void confirmation (CRD-5, PD2) submits: an optional reason for the audit log and the
 * return target (`from`, PD4).
 */
final class VoidData
{
    #[Assert\Length(max: CreditLedger::REASON_MAX, maxMessage: 'credits.void.reason.too_long')]
    public ?string $reason = null;

    public ?string $from = null;
}
