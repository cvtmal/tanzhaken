<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidTeacher;
use App\Shared\Time\Timezones;
use PHPUnit\Framework\TestCase;

final class TeacherTest extends TestCase
{
    public function test_it_normalises_the_email(): void
    {
        $teacher = $this->teacher(email: ' Ann@Example.COM ');

        self::assertSame('ann@example.com', $teacher->getEmail());
        self::assertSame('ann@example.com', $teacher->getUserIdentifier());

        $teacher->changeEmail(' Other@Example.org');
        self::assertSame('other@example.org', $teacher->getEmail());
    }

    public function test_it_is_a_teacher_with_its_own_id_as_owner(): void
    {
        $teacher = $this->teacher();

        self::assertSame(['ROLE_TEACHER'], $teacher->getRoles());
        self::assertSame('hash', $teacher->getPassword());
        self::assertTrue($teacher->getId()->equals($teacher->ownerId()));
        self::assertSame(Timezones::DEFAULT, $teacher->getTimezone());
    }

    public function test_its_id_is_a_uuid_v7(): void
    {
        self::assertSame('7', $this->teacher()->getId()->toRfc4122()[14]);
    }

    public function test_it_rejects_an_invalid_timezone(): void
    {
        $this->expectException(InvalidTeacher::class);

        $this->teacher(timezone: 'Mars/Base');
    }

    public function test_it_rejects_an_invalid_timezone_on_change(): void
    {
        $teacher = $this->teacher();

        $this->expectException(InvalidTeacher::class);

        $teacher->changeTimezone('Mars/Base');
    }

    public function test_it_rejects_an_empty_name(): void
    {
        $this->expectException(InvalidTeacher::class);

        $this->teacher(name: '   ');
    }

    public function test_it_rejects_an_empty_email(): void
    {
        $this->expectException(InvalidTeacher::class);

        $this->teacher(email: ' ');
    }

    public function test_the_serialised_form_does_not_contain_the_password_hash(): void
    {
        $teacher = $this->teacher();
        $teacher->changePasswordHash('$2y$13$secret-hash-value');

        self::assertStringNotContainsString('secret-hash-value', serialize($teacher));
    }

    private function teacher(string $name = 'Ann Example', string $email = 'ann@example.com', string $timezone = Timezones::DEFAULT): Teacher
    {
        return new Teacher($name, $email, 'hash', new \DateTimeImmutable('2026-11-10 10:00:00'), $timezone);
    }
}
