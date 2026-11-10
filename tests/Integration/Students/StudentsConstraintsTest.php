<?php

declare(strict_types=1);

namespace App\Tests\Integration\Students;

use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentsConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_student_needs_an_existing_teacher(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('student', [
            'id' => Uuid::v7(),
            'teacher_id' => Uuid::v7(),
            'first_name' => 'Ada',
            'last_name' => 'Nobody',
            'created_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
        ]);
    }
}
