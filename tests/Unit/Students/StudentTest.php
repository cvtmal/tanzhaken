<?php

declare(strict_types=1);

namespace App\Tests\Unit\Students;

use App\Account\Entity\Teacher;
use App\Students\Entity\Student;
use App\Students\Exception\InvalidStudent;
use PHPUnit\Framework\TestCase;

final class StudentTest extends TestCase
{
    public function test_it_belongs_to_its_teacher(): void
    {
        $teacher = $this->teacher();
        $student = new Student($teacher, ' Mia ', ' Example ', $this->now());

        self::assertSame('Mia', $student->getFirstName());
        self::assertSame('Example', $student->getLastName());
        self::assertTrue($teacher->getId()->equals($student->ownerId()));
        self::assertSame('7', $student->getId()->toRfc4122()[14]);
        self::assertNull($student->getEmail());
        self::assertNull($student->getNotes());
    }

    public function test_it_rejects_a_blank_first_name(): void
    {
        $this->expectException(InvalidStudent::class);

        new Student($this->teacher(), '  ', 'Example', $this->now());
    }

    public function test_it_rejects_a_blank_last_name(): void
    {
        $student = new Student($this->teacher(), 'Mia', 'Example', $this->now());

        $this->expectException(InvalidStudent::class);

        $student->changeDetails('Mia', ' ', null, null);
    }

    public function test_it_normalises_the_email_and_empties_to_null(): void
    {
        $student = new Student($this->teacher(), 'Mia', 'Example', $this->now(), ' Mia@Example.COM ', ' ');

        self::assertSame('mia@example.com', $student->getEmail());
        self::assertNull($student->getNotes());

        $student->changeDetails('Mia', 'Example', '  ', 'Prefers the front row');
        self::assertNull($student->getEmail());
        self::assertSame('Prefers the front row', $student->getNotes());
    }

    public function test_it_archives_and_restores(): void
    {
        $student = new Student($this->teacher(), 'Mia', 'Example', $this->now());
        $later = $this->now()->modify('+1 day');

        $student->archive($later);
        self::assertTrue($student->isArchived());
        self::assertEquals($later, $student->getArchivedAt());

        $student->restore();
        self::assertFalse($student->isArchived());
        self::assertNull($student->getArchivedAt());
    }

    public function test_it_cannot_be_archived_twice(): void
    {
        $student = new Student($this->teacher(), 'Mia', 'Example', $this->now());
        $student->archive($this->now());

        $this->expectException(InvalidStudent::class);

        $student->archive($this->now());
    }

    private function teacher(): Teacher
    {
        return new Teacher('Ann Example', 'ann@example.com', 'hash', $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 10:00:00');
    }
}
