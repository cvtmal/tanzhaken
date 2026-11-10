<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Shared\Audit\ActorType;
use App\Shared\Entity\AuditEvent;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AuditEvent>
 */
final class AuditEventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AuditEvent::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'teacher' => TeacherFactory::new(),
            'actorType' => ActorType::Teacher,
            'action' => 'test.recorded',
            'entityType' => 'test',
            'entityId' => Uuid::v7(),
            'occurredAt' => Clock::get()->now(),
            'details' => [],
        ];
    }
}
