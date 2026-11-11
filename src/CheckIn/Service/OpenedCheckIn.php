<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use Symfony\Component\Uid\Uuid;

/**
 * The result of CheckInWindows::open(): the new window and its plain token.
 */
final readonly class OpenedCheckIn
{
    /**
     * @param string $token the only place the plain token exists (the database keeps its hash);
     *                      #16 keeps it in the session keyed by the window id (`docs/rpi-cycles.md` §16)
     */
    public function __construct(
        public Uuid $windowId,
        public Uuid $lessonId,
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
