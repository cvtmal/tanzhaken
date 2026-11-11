<?php

declare(strict_types=1);

namespace App\Students\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * A foreign or unknown ID; the controller answers 404 (S4).
 */
final class StudentNotFound extends \RuntimeException
{
    public static function student(Uuid $id): self
    {
        return new self(\sprintf('Student %s not found.', $id->toRfc4122()));
    }
}
