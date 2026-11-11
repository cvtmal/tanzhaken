<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Exception\InvalidTeacher;
use App\Shared\Security\TeacherAccount;
use App\Shared\Security\TeacherOwned;
use App\Shared\Time\Timezones;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'teacher')]
#[ORM\UniqueConstraint(name: 'uk_teacher_email', columns: ['email'])]
class Teacher implements UserInterface, PasswordAuthenticatedUserInterface, TeacherOwned, TeacherAccount
{
    public const NAME_MAX = 120;
    public const EMAIL_MAX = 180;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** @var non-empty-string */
    #[ORM\Column(length: self::EMAIL_MAX)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $email,
        string $passwordHash,
        \DateTimeImmutable $now,
        string $timezone = Timezones::DEFAULT,
    ) {
        $this->id = Uuid::v7();
        $this->name = self::normaliseName($name);
        $this->email = self::normaliseEmail($email);
        $this->passwordHash = $passwordHash;
        $this->timezone = self::guardTimezone($timezone);
        $this->createdAt = $now;
    }

    /**
     * Trimmed and lower-cased; the repository looks emails up the same way.
     *
     * @return non-empty-string
     */
    public static function normaliseEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ('' === $email) {
            throw InvalidTeacher::emptyEmail();
        }
        if (mb_strlen($email) > self::EMAIL_MAX) {
            throw InvalidTeacher::emailTooLong(self::EMAIL_MAX);
        }

        return $email;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The name shown in the teacher layout (sidebar avatar and name) and on public pages.
     */
    public function getDisplayName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The password hash (PasswordAuthenticatedUserInterface).
     */
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function rename(string $name): void
    {
        $this->name = self::normaliseName($name);
    }

    public function changeEmail(string $email): void
    {
        $this->email = self::normaliseEmail($email);
    }

    public function changePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function changeTimezone(string $timezone): void
    {
        $this->timezone = self::guardTimezone($timezone);
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_TEACHER'];
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /**
     * The session stores a checksum of the password hash, never the hash itself.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0passwordHash"] = hash('crc32c', $this->passwordHash);

        return $data;
    }

    private static function normaliseName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw InvalidTeacher::emptyName();
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw InvalidTeacher::nameTooLong(self::NAME_MAX);
        }

        return $name;
    }

    private static function guardTimezone(string $timezone): string
    {
        if (!Timezones::isValid($timezone)) {
            throw InvalidTeacher::invalidTimezone($timezone);
        }

        return $timezone;
    }
}
