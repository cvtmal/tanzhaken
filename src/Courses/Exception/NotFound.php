<?php

declare(strict_types=1);

namespace App\Courses\Exception;

use Symfony\Component\Uid\Uuid;

final class NotFound extends \RuntimeException
{
    public static function course(Uuid $id): self
    {
        return new self(\sprintf('Course "%s" not found.', $id->toRfc4122()));
    }

    public static function lesson(Uuid $id): self
    {
        return new self(\sprintf('Lesson "%s" not found.', $id->toRfc4122()));
    }
}
