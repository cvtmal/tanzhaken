<?php

declare(strict_types=1);

namespace App\Account\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One pending password-reset link (ACC-3). Only the SHA-256 of the emailed token is stored;
 * a teacher has at most one row (PasswordResets deletes older ones), and a used link is deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset_request')]
#[ORM\UniqueConstraint(name: 'uk_password_reset_request_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_password_reset_request_teacher', columns: ['teacher_id'])]
class PasswordResetRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Teacher $teacher, string $tokenHash, \DateTimeImmutable $requestedAt, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->teacher = $teacher;
        $this->tokenHash = $tokenHash;
        $this->requestedAt = $requestedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
