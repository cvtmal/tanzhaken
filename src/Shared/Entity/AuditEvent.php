<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Audit\ActorType;
use App\Shared\Security\TeacherAccount;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One immutable audit row (D7, D8). Written by App\Shared\Audit\AuditLogger;
 * the entity exists for the mapping, reads and factories. No mutators.
 *
 * The teacher is the Shared interface TeacherAccount, resolved to the Account
 * module's Teacher by Doctrine, so the foreign key is ordinary mapping.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_event')]
#[ORM\Index(name: 'idx_audit_event_teacher_occurred', columns: ['teacher_id', 'occurred_at'])]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TeacherAccount::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false, onDelete: 'CASCADE')]
    private TeacherAccount $teacher;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 64)]
    private string $entityType;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $entityId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'details_json', type: Types::JSON)]
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        TeacherAccount $teacher,
        ActorType $actorType,
        string $action,
        string $entityType,
        Uuid $entityId,
        \DateTimeImmutable $occurredAt,
        array $details = [],
    ) {
        $this->id = Uuid::v7();
        $this->teacher = $teacher;
        $this->actorType = $actorType;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->occurredAt = $occurredAt;
        $this->details = $details;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTeacher(): TeacherAccount
    {
        return $this->teacher;
    }

    public function getTeacherId(): Uuid
    {
        return $this->teacher->getId();
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
