<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\Service\CheckInTokenStore;
use App\CheckIn\Service\OpenedCheckIn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/**
 * The plain token of an opened check-in in the teacher's session (#16 bullet 3): returned only for
 * the window it was issued for, forgotten on close, one entry per lesson.
 */
final class CheckInTokenStoreTest extends TestCase
{
    private CheckInTokenStore $store;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->store = new CheckInTokenStore($requestStack);
    }

    public function test_a_remembered_token_is_returned_for_its_window(): void
    {
        $opened = self::opened('token-one');

        $this->store->remember($opened);

        self::assertSame('token-one', $this->store->tokenFor($opened->lessonId, $opened->windowId));
    }

    public function test_another_window_of_the_lesson_gets_no_token(): void
    {
        $opened = self::opened('token-one');
        $this->store->remember($opened);

        self::assertNull($this->store->tokenFor($opened->lessonId, Uuid::v7()));
    }

    public function test_a_forgotten_token_is_gone(): void
    {
        $opened = self::opened('token-one');
        $this->store->remember($opened);

        $this->store->forget($opened->lessonId);

        self::assertNull($this->store->tokenFor($opened->lessonId, $opened->windowId));
    }

    public function test_two_lessons_are_kept_apart(): void
    {
        $first = self::opened('token-one');
        $second = self::opened('token-two');
        $this->store->remember($first);
        $this->store->remember($second);

        self::assertSame('token-one', $this->store->tokenFor($first->lessonId, $first->windowId));
        self::assertSame('token-two', $this->store->tokenFor($second->lessonId, $second->windowId));
        self::assertNull($this->store->tokenFor($first->lessonId, $second->windowId));

        $this->store->forget($first->lessonId);

        self::assertNull($this->store->tokenFor($first->lessonId, $first->windowId));
        self::assertSame('token-two', $this->store->tokenFor($second->lessonId, $second->windowId));
    }

    public function test_an_unknown_lesson_gets_no_token(): void
    {
        self::assertNull($this->store->tokenFor(Uuid::v7(), Uuid::v7()));
    }

    private static function opened(string $token): OpenedCheckIn
    {
        return new OpenedCheckIn(Uuid::v7(), Uuid::v7(), $token, new \DateTimeImmutable('2026-11-10 20:30:00', new \DateTimeZone('UTC')));
    }
}
