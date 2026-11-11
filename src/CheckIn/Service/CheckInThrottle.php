<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the public check-in (config/packages/rate_limiter_checkin.yaml).
 *
 * #14 calls allowIp() before any query and allowToken() after the token's format check,
 * keyed by CheckInTokens::hash() of the token, never the plain token. Each method returns
 * null when the request may proceed, else the moment the client may retry.
 */
final class CheckInThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $checkinIpLimiter,
        private readonly RateLimiterFactoryInterface $checkinTokenLimiter,
    ) {
    }

    public function allowIp(string $ip): ?\DateTimeImmutable
    {
        return self::consume($this->checkinIpLimiter, $ip);
    }

    public function allowToken(string $tokenHash): ?\DateTimeImmutable
    {
        return self::consume($this->checkinTokenLimiter, $tokenHash);
    }

    private static function consume(RateLimiterFactoryInterface $factory, string $key): ?\DateTimeImmutable
    {
        $limit = $factory->create($key)->consume(1);

        return $limit->isAccepted() ? null : $limit->getRetryAfter();
    }
}
