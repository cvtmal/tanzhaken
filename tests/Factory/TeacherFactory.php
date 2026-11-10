<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Account\Entity\Teacher;
use App\Shared\Time\Timezones;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Teacher>
 */
final class TeacherFactory extends PersistentObjectFactory
{
    /** Demo login password for local development and tests (demo data, not a credential). */
    public const DEMO_PASSWORD = 'tanzhaken-demo';

    /** bcrypt hash of {@see self::DEMO_PASSWORD}; verifies under the `auto` hasher. */
    public const DEMO_PASSWORD_HASH = '$2y$12$DaEaiMDx7B9cBWjDTqLEReayass/1c0XBwqgUryXme6ep3R3UatNS';

    public static function class(): string
    {
        return Teacher::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->name(),
            'email' => self::faker()->unique()->safeEmail(),
            'passwordHash' => self::DEMO_PASSWORD_HASH,
            'now' => Clock::get()->now(),
            'timezone' => Timezones::DEFAULT,
        ];
    }
}
