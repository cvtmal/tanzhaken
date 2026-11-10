<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Audit\ActorType;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AuditEventConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_audit_event_needs_an_existing_teacher(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('audit_event', [
            'id' => Uuid::v7(),
            'teacher_id' => Uuid::v7(),
            'actor_type' => ActorType::Teacher,
            'action' => 'test.recorded',
            'entity_type' => 'test',
            'entity_id' => Uuid::v7(),
            'occurred_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
            'details_json' => '{}',
        ]);
    }
}
