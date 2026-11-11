<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * Check-in tokens (S8): 32 random bytes as unpadded base64url (43 characters);
 * the database keeps only their lower-case hex SHA-256.
 */
final class CheckInTokens
{
    public function issue(): IssuedToken
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new IssuedToken($plain, $this->hash($plain));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isWellFormed(string $token): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/D', $token);
    }
}
