<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Credits\Repository\CreditGrantRepository;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreditGrantRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_grant(): void
    {
        self::bootKernel();
        $grant = CreditGrantFactory::createOne();
        $teacher = $grant->getEnrollment()->getCourse()->getTeacher();
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::getContainer()->get(CreditGrantRepository::class);
        self::assertInstanceOf(CreditGrantRepository::class, $repository);

        self::assertSame($grant, $repository->findOwned($teacher, $grant->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $grant->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }
}
