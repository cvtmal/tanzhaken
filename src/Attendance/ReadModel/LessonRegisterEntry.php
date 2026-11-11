<?php

declare(strict_types=1);

namespace App\Attendance\ReadModel;

use App\Attendance\Entity\AttendanceStatus;
use App\Credits\ReadModel\EnrollmentBalance;

/**
 * One row of the lesson page (#13, P4): the register row and the enrollment's balance from
 * Balances (null for a student without an enrollment of the course). The cost preview moves
 * Balances' number by one lesson; the formula itself is never recomputed (S2).
 */
final readonly class LessonRegisterEntry
{
    public function __construct(
        public LessonRegisterRow $row,
        public ?EnrollmentBalance $balance,
    ) {
    }

    /**
     * Merges the register with Balances::forCourse() by enrollment id, keeping the register's order.
     *
     * @param list<LessonRegisterRow>          $rows
     * @param array<string, EnrollmentBalance> $balances keyed by enrollment id (RFC 4122)
     *
     * @return list<self>
     */
    public static function fromRegister(array $rows, array $balances): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $key = $row->enrollmentId?->toRfc4122();
            $entries[] = new self($row, null !== $key ? ($balances[$key] ?? null) : null);
        }

        return $entries;
    }

    public function isMarked(): bool
    {
        return null !== $this->row->status;
    }

    public function isPresent(): bool
    {
        return AttendanceStatus::Present === $this->row->status;
    }

    public function balance(): ?int
    {
        return $this->balance?->balance;
    }

    /**
     * The balance once marked present: one lesson less, unless the student is already present.
     */
    public function balanceIfPresent(): ?int
    {
        $balance = $this->balance();
        if (null === $balance) {
            return null;
        }

        return $this->isPresent() ? $balance : $balance - 1;
    }

    /**
     * The balance once marked absent: a present lesson comes back, otherwise nothing changes.
     */
    public function balanceIfAbsent(): ?int
    {
        $balance = $this->balance();
        if (null === $balance) {
            return null;
        }

        return $this->isPresent() ? $balance + 1 : $balance;
    }

    /**
     * First name and last initial ("Alex M.", design F2).
     */
    public function displayName(): string
    {
        $initial = mb_substr(trim($this->row->lastName), 0, 1);

        return '' === $initial ? $this->row->firstName : $this->row->firstName.' '.$initial.'.';
    }

    /**
     * The row's fragment id, the target of the redirect after a row action (R12).
     */
    public function anchor(): string
    {
        return 'student-'.$this->row->studentId->toRfc4122();
    }
}
