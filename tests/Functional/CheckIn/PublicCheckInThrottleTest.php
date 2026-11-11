<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\CheckIn\Service\CheckInThrottle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * S8 limits on the public check-in: the IP limiter and the token limiter answer 429 with
 * `Retry-After` on GET and POST (plan P3: exhausted through CheckInThrottle, not by config).
 */
#[ResetDatabase]
final class PublicCheckInThrottleTest extends WebTestCase
{
    use OpensPublicCheckIn;

    /** BrowserKit's client address. */
    private const CLIENT_IP = '127.0.0.1';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->resetCheckInLimits($this->browser);
    }

    public function test_an_exhausted_ip_limit_answers_429_on_get_and_post(): void
    {
        [, , [$anna]] = self::openPublicCheckIn();
        self::exhaustLimit(static fn (CheckInThrottle $throttle): ?\DateTimeImmutable => $throttle->allowIp(self::CLIENT_IP));

        $this->browser->request('GET', '/c/'.self::TOKEN);
        $this->assertTooMany();
        self::confirm($this->browser, $anna->getId()->toRfc4122());
        $this->assertTooMany();

        self::assertSame(0, self::attendanceCount());
        self::assertSame(10, self::balanceOf($anna));
    }

    public function test_an_exhausted_token_limit_answers_429_on_get_and_post(): void
    {
        [, , [$anna]] = self::openPublicCheckIn();
        self::exhaustLimit(static fn (CheckInThrottle $throttle): ?\DateTimeImmutable => $throttle->allowToken(hash('sha256', self::TOKEN)));

        $this->browser->request('GET', '/c/'.self::TOKEN);
        $this->assertTooMany();
        self::confirm($this->browser, $anna->getId()->toRfc4122());
        $this->assertTooMany();

        self::assertSame(0, self::attendanceCount());
    }

    public function test_a_reset_limiter_lets_the_visitor_in_again(): void
    {
        self::openPublicCheckIn();
        self::exhaustLimit(static fn (CheckInThrottle $throttle): ?\DateTimeImmutable => $throttle->allowIp(self::CLIENT_IP));
        $this->browser->request('GET', '/c/'.self::TOKEN);
        $this->assertTooMany();

        $this->resetCheckInLimits($this->browser);
        $this->browser->request('GET', '/c/'.self::TOKEN);

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('h1', self::COURSE_NAME);
    }

    private function assertTooMany(): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', 'Too many attempts');
        self::assertSelectorNotExists('form');
        $retryAfter = $this->browser->getResponse()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
    }
}
