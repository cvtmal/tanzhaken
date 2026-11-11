<?php

declare(strict_types=1);

namespace App\Account\Service;

/**
 * A freshly issued reset token: the plain value goes into the mail, only the hash is stored.
 */
final readonly class IssuedResetToken
{
    public function __construct(
        public string $plain,
        public string $hash,
    ) {
    }
}
