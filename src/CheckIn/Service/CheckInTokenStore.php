<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps the plain token of an opened check-in in the teacher's session, one entry per lesson
 * (`checkin.token.<lesson id>`). The session is the only place the plain token lives (#8: the
 * database keeps its hash). A token is returned only for the window it was issued for, so a
 * rotated or closed window never shows a stale code.
 */
final class CheckInTokenStore
{
    private const KEY_PREFIX = 'checkin.token.';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function remember(OpenedCheckIn $opened): void
    {
        $this->requestStack->getSession()->set(self::key($opened->lessonId), [
            'windowId' => $opened->windowId->toRfc4122(),
            'token' => $opened->token,
        ]);
    }

    public function tokenFor(Uuid $lessonId, Uuid $activeWindowId): ?string
    {
        $stored = $this->stored($lessonId);
        if (null === $stored || $stored->windowId !== $activeWindowId->toRfc4122()) {
            return null;
        }

        return $stored->token;
    }

    public function forget(Uuid $lessonId): void
    {
        $this->requestStack->getSession()->remove(self::key($lessonId));
    }

    private function stored(Uuid $lessonId): ?StoredCheckInToken
    {
        $value = $this->requestStack->getSession()->get(self::key($lessonId));
        if (!\is_array($value) || !\is_string($value['windowId'] ?? null) || !\is_string($value['token'] ?? null)) {
            return null;
        }

        return new StoredCheckInToken($value['windowId'], $value['token']);
    }

    private static function key(Uuid $lessonId): string
    {
        return self::KEY_PREFIX.$lessonId->toRfc4122();
    }
}
