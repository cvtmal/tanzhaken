<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\Uid\Uuid;

/**
 * A private row that leads to exactly one teacher (S4, D9). The voter reads it.
 */
interface TeacherOwned
{
    public function ownerId(): Uuid;
}
