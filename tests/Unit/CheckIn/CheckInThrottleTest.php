<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\Service\CheckInThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * The limiter services stay out of the compiled container until #14 injects the throttle
 * (plan P7), so the factories are built from the config file itself.
 */
final class CheckInThrottleTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../../config/packages/rate_limiter_checkin.yaml';

    public function test_the_config_file_holds_the_agreed_limits(): void
    {
        self::assertSame(
            ['policy' => 'sliding_window', 'limit' => 100, 'interval' => '5 minutes'],
            self::limiterConfig('checkin_ip'),
        );
        self::assertSame(
            ['policy' => 'sliding_window', 'limit' => 300, 'interval' => '60 minutes'],
            self::limiterConfig('checkin_token'),
        );
    }

    public function test_the_ip_limiter_refuses_the_101st_request_of_one_ip_only(): void
    {
        $throttle = self::throttle();

        $retryAfters = [];
        for ($i = 1; $i <= 100; ++$i) {
            $retryAfters[] = $throttle->allowIp('192.0.2.10');
        }
        self::assertSame(array_fill(0, 100, null), $retryAfters);

        $retryAfter = $throttle->allowIp('192.0.2.10');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(new \DateTimeImmutable(), $retryAfter);
        self::assertNull($throttle->allowIp('192.0.2.11'));
    }

    public function test_the_token_limiter_refuses_the_301st_request_of_one_hash_only(): void
    {
        $throttle = self::throttle();
        $hash = str_repeat('a', 64);

        $retryAfters = [];
        for ($i = 1; $i <= 300; ++$i) {
            $retryAfters[] = $throttle->allowToken($hash);
        }
        self::assertSame(array_fill(0, 300, null), $retryAfters);

        $retryAfter = $throttle->allowToken($hash);
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(new \DateTimeImmutable(), $retryAfter);
        self::assertNull($throttle->allowToken(str_repeat('b', 64)));
    }

    private static function throttle(): CheckInThrottle
    {
        return new CheckInThrottle(self::factory('checkin_ip'), self::factory('checkin_token'));
    }

    private static function factory(string $name): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $name] + self::limiterConfig($name), new InMemoryStorage());
    }

    /**
     * @return array<string, mixed>
     */
    private static function limiterConfig(string $name): array
    {
        $config = Yaml::parseFile(self::CONFIG);
        self::assertIsArray($config);
        $limiter = $config['framework']['rate_limiter'][$name] ?? null;
        self::assertIsArray($limiter, "limiter {$name} missing");

        /* @var array<string, mixed> $limiter */
        return $limiter;
    }
}
