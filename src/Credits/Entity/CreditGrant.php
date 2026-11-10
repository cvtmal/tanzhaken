<?php

declare(strict_types=1);

namespace App\Credits\Entity;

use App\Courses\Entity\Enrollment;
use App\Credits\Exception\InvalidCreditGrant;
use App\Shared\Security\TeacherOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lessons granted to one enrollment (S9: a pass of 10 by default). Voiding keeps the row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_grant')]
#[ORM\Index(name: 'idx_credit_grant_enrollment', columns: ['enrollment_id'])]
class CreditGrant implements TeacherOwned
{
    public const DEFAULT_QUANTITY = 10;
    public const NOTE_MAX = 255;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrollment $enrollment;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    public function __construct(Enrollment $enrollment, \DateTimeImmutable $now, int $quantity = self::DEFAULT_QUANTITY, ?string $note = null)
    {
        if ($quantity < 1) {
            throw InvalidCreditGrant::quantityNotPositive($quantity);
        }
        $note = trim($note ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw InvalidCreditGrant::noteTooLong(self::NOTE_MAX);
        }

        $this->id = Uuid::v7();
        $this->enrollment = $enrollment;
        $this->quantity = $quantity;
        $this->grantedAt = $now;
        $this->note = '' === $note ? null : $note;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->enrollment->ownerId();
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getVoidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function void(\DateTimeImmutable $now): void
    {
        if (null !== $this->voidedAt) {
            throw InvalidCreditGrant::alreadyVoided();
        }
        $this->voidedAt = $now;
    }

    public function isVoided(): bool
    {
        return null !== $this->voidedAt;
    }
}
