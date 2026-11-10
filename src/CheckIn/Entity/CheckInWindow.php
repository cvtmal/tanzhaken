<?php

declare(strict_types=1);

namespace App\CheckIn\Entity;

use App\CheckIn\Exception\InvalidCheckInWindow;
use App\Courses\Entity\LessonSession;
use App\Shared\Security\TeacherOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A time-boxed QR check-in for one lesson. Only the SHA-256 of the token is stored (S8).
 * "One active window per lesson" is enforced by #8's service, not by the database (D4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'check_in_window')]
#[ORM\UniqueConstraint(name: 'uk_check_in_window_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_check_in_window_lesson', columns: ['lesson_session_id'])]
class CheckInWindow implements TeacherOwned
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LessonSession $lessonSession;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(LessonSession $lessonSession, string $tokenHash, \DateTimeImmutable $openedAt, \DateTimeImmutable $expiresAt)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw InvalidCheckInWindow::invalidTokenHash();
        }
        if ($expiresAt <= $openedAt) {
            throw InvalidCheckInWindow::expiresBeforeOpening();
        }

        $this->id = Uuid::v7();
        $this->lessonSession = $lessonSession;
        $this->tokenHash = $tokenHash;
        $this->openedAt = $openedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->lessonSession->ownerId();
    }

    public function getLessonSession(): LessonSession
    {
        return $this->lessonSession;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function close(\DateTimeImmutable $now): void
    {
        if (null !== $this->closedAt) {
            throw InvalidCheckInWindow::alreadyClosed();
        }
        $this->closedAt = $now;
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return null === $this->closedAt && $now < $this->expiresAt;
    }
}
