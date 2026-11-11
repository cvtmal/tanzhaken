<?php

declare(strict_types=1);

namespace App\Credits\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * A foreign or unknown ID; the controller answers 404 (S4).
 */
final class CreditNotFound extends \RuntimeException
{
    public static function enrollment(Uuid $id): self
    {
        return new self(\sprintf('Enrollment %s not found.', $id->toRfc4122()));
    }

    public static function grant(Uuid $id): self
    {
        return new self(\sprintf('Credit grant %s not found.', $id->toRfc4122()));
    }
}
