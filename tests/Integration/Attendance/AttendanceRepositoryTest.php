<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Repository\AttendanceRepository;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AttendanceRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_attendance(): void
    {
        self::bootKernel();
        $attendance = AttendanceFactory::createOne();
        $teacher = $attendance->getLessonSession()->getCourse()->getTeacher();
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::getContainer()->get(AttendanceRepository::class);
        self::assertInstanceOf(AttendanceRepository::class, $repository);

        self::assertSame($attendance, $repository->findOwned($teacher, $attendance->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $attendance->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }
}
