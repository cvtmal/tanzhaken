<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Appends one audit_event row (D8). Uses the default connection, so it joins the
 * caller's transaction.
 */
final class AuditLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(
        Uuid $teacherId,
        ActorType $actor,
        string $action,
        string $entityType,
        Uuid $entityId,
        array $details = [],
    ): void {
        $this->connection->insert('audit_event', [
            'id' => Uuid::v7(),
            'teacher_id' => $teacherId,
            'actor_type' => $actor->value,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'occurred_at' => $this->clock->now(),
            'details_json' => $details,
        ], [
            'id' => UuidType::NAME,
            'teacher_id' => UuidType::NAME,
            'entity_id' => UuidType::NAME,
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'details_json' => Types::JSON,
        ]);
    }
}
