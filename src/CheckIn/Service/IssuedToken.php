<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * A freshly issued check-in token (S8): the plain value goes into the QR code once,
 * only the hash is stored.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $plain,
        public string $hash,
    ) {
    }
}
