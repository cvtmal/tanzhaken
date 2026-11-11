<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * The plain token of an opened check-in as kept in the teacher's session, with the window it belongs to.
 */
final readonly class StoredCheckInToken
{
    public function __construct(
        public string $windowId,
        public string $token,
    ) {
    }
}
