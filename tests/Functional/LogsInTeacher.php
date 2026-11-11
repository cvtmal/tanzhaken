<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Account\Entity\Teacher;
use App\Tests\Factory\TeacherFactory;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Logs a teacher into the `main` firewall of a functional test client (#4).
 *
 * Call the helpers after `createClient()`: booting the kernel first (a factory call or
 * `getContainer()`) makes `createClient()` throw. The class needs `#[ResetDatabase]`, since
 * `logInTeacher()` creates a row.
 *
 * Every test that submits the login form calls `resetLoginThrottling()` first (D-030): the
 * login limiter stores its counters in the `cache.rate_limiter` pool on the filesystem,
 * which outlives the rolled-back database transaction of each test.
 */
trait LogsInTeacher
{
    private function logInTeacher(KernelBrowser $client, ?Teacher $teacher = null): Teacher
    {
        $teacher ??= TeacherFactory::createOne();
        $client->loginUser($teacher, 'main');

        return $teacher;
    }

    private function resetLoginThrottling(KernelBrowser $client): void
    {
        $pool = $client->getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
    }
}
