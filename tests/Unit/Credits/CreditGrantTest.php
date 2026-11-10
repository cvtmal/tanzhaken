<?php

declare(strict_types=1);

namespace App\Tests\Unit\Credits;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Credits\Entity\CreditGrant;
use App\Credits\Exception\InvalidCreditGrant;
use App\Students\Entity\Student;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CreditGrantTest extends TestCase
{
    public function test_a_pass_has_ten_lessons_by_default(): void
    {
        $enrollment = self::enrollment();
        $grant = new CreditGrant($enrollment, self::now(), note: ' ');

        self::assertSame(10, $grant->getQuantity());
        self::assertNull($grant->getNote());
        self::assertFalse($grant->isVoided());
        self::assertTrue($enrollment->ownerId()->equals($grant->ownerId()));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_it_refuses_a_quantity_below_one(int $quantity): void
    {
        $this->expectException(InvalidCreditGrant::class);

        new CreditGrant(self::enrollment(), self::now(), $quantity);
    }

    public function test_it_voids_once(): void
    {
        $grant = new CreditGrant(self::enrollment(), self::now(), 5, 'Trial');
        $grant->void(self::now());

        self::assertTrue($grant->isVoided());
        self::assertEquals(self::now(), $grant->getVoidedAt());

        $this->expectException(InvalidCreditGrant::class);
        $grant->void(self::now());
    }

    private static function enrollment(): Enrollment
    {
        $teacher = new Teacher('Ann Example', 'ann@example.com', 'hash', self::now());
        $course = new Course($teacher, 'Salsa', 'Europe/Zurich', 3, new \DateTimeImmutable('19:30'), 60, new \DateTimeImmutable('2026-09-01'), self::now());

        return new Enrollment($course, new Student($teacher, 'Mia', 'Example', self::now()), self::now());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 10:00:00');
    }
}
