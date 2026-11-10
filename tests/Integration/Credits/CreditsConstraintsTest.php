<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreditsConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_credit_grant_needs_an_existing_enrollment(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('credit_grant', [
            'id' => Uuid::v7(),
            'enrollment_id' => Uuid::v7(),
            'quantity' => 10,
            'granted_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
        ]);
    }
}
