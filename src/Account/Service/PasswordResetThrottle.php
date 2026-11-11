<?php

declare(strict_types=1);

namespace App\Account\Service;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles "forgot password" (config/packages/rate_limiter_account.yaml).
 *
 * allowIp() is checked by the controller and its refusal is shown; allowEmail() is checked by
 * PasswordResets::request() and its refusal is silent. The email key is the sha256 of the
 * normalised email, never the address itself. Each method returns null when the request may
 * proceed, else the moment the client may retry.
 */
final class PasswordResetThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $passwordForgotIpLimiter,
        private readonly RateLimiterFactoryInterface $passwordForgotEmailLimiter,
    ) {
    }

    public function allowIp(string $ip): ?\DateTimeImmutable
    {
        return self::consume($this->passwordForgotIpLimiter, $ip);
    }

    public function allowEmail(string $normalisedEmail): ?\DateTimeImmutable
    {
        return self::consume($this->passwordForgotEmailLimiter, hash('sha256', $normalisedEmail));
    }

    private static function consume(RateLimiterFactoryInterface $factory, string $key): ?\DateTimeImmutable
    {
        $limit = $factory->create($key)->consume(1);

        return $limit->isAccepted() ? null : $limit->getRetryAfter();
    }
}
