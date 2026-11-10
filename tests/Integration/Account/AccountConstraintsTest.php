<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccountConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_teacher_email_is_unique(): void
    {
        self::bootKernel();
        TeacherFactory::createOne(['email' => 'taken@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/teacher\.email/');

        self::insertRow('teacher', [
            'id' => Uuid::v7(),
            'name' => 'Second Teacher',
            'email' => 'taken@example.com',
            'password_hash' => TeacherFactory::DEMO_PASSWORD_HASH,
            'timezone' => 'Europe/Zurich',
            'created_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
        ]);
    }
}
