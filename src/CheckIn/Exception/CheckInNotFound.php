<?php

declare(strict_types=1);

namespace App\CheckIn\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * An unknown id or one of another teacher (S4: the controller answers 404).
 */
final class CheckInNotFound extends \DomainException
{
    public static function lesson(Uuid $id): self
    {
        return new self(\sprintf('Lesson "%s" not found.', $id->toRfc4122()));
    }
}
