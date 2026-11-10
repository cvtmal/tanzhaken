<?php

declare(strict_types=1);

namespace App\Students\Entity;

use App\Account\Entity\Teacher;
use App\Shared\Security\TeacherOwned;
use App\Students\Exception\InvalidStudent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'student')]
#[ORM\Index(name: 'idx_student_teacher_archived', columns: ['teacher_id', 'archived_at'])]
class Student implements TeacherOwned
{
    public const NAME_MAX = 80;
    public const EMAIL_MAX = 180;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $firstName;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $lastName;

    #[ORM\Column(length: self::EMAIL_MAX, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        Teacher $teacher,
        string $firstName,
        string $lastName,
        \DateTimeImmutable $now,
        ?string $email = null,
        ?string $notes = null,
    ) {
        $this->id = Uuid::v7();
        $this->teacher = $teacher;
        $this->createdAt = $now;
        $this->changeDetails($firstName, $lastName, $email, $notes);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->teacher->getId();
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Names are trimmed and required; the email is trimmed and lower-cased; an empty email or notes becomes null.
     */
    public function changeDetails(string $firstName, string $lastName, ?string $email, ?string $notes): void
    {
        $firstName = self::guardName($firstName, 'first name');
        $lastName = self::guardName($lastName, 'last name');

        $email = mb_strtolower(trim($email ?? ''));
        if (mb_strlen($email) > self::EMAIL_MAX) {
            throw InvalidStudent::tooLong('email', self::EMAIL_MAX);
        }
        $notes = trim($notes ?? '');

        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = '' === $email ? null : $email;
        $this->notes = '' === $notes ? null : $notes;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        if (null !== $this->archivedAt) {
            throw InvalidStudent::alreadyArchived();
        }
        $this->archivedAt = $now;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    private static function guardName(string $name, string $field): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw 'first name' === $field ? InvalidStudent::emptyFirstName() : InvalidStudent::emptyLastName();
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw InvalidStudent::tooLong($field, self::NAME_MAX);
        }

        return $name;
    }
}
