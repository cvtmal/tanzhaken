<?php

declare(strict_types=1);

namespace App\Students\ReadModel;

use Symfony\Component\Uid\Uuid;

/**
 * One row of the student directory (`student_index`).
 */
final readonly class StudentListItem
{
    public function __construct(
        public Uuid $id,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?\DateTimeImmutable $archivedAt,
        public int $activeCourseCount,
    ) {
    }

    public function fullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    /** First letter of each name, upper case (the row's avatar). */
    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1));
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }
}
