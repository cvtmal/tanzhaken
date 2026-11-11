<?php

declare(strict_types=1);

namespace App\Credits\Controller;

/**
 * Where a credit page returns to after success (PD4): `?from=course|student|dashboard`, carried
 * as the forms' hidden `from` field. Any other value falls back to the default, so no URL from
 * the request is ever redirected to (no open redirect).
 */
enum ReturnTarget: string
{
    case Course = 'course';
    case Student = 'student';
    case Dashboard = 'dashboard';

    public static function fromQuery(?string $value, self $default = self::Course): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }
}
