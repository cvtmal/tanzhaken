<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\CheckIn\Repository\CheckInWindowRepository;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInWindowRepositoryTest extends KernelTestCase
{
    public function test_find_owned_returns_only_the_teachers_window(): void
    {
        self::bootKernel();
        $window = CheckInWindowFactory::createOne();
        $teacher = $window->getLessonSession()->getCourse()->getTeacher();
        $otherTeacher = TeacherFactory::createOne();
        $repository = self::repository();

        self::assertSame($window, $repository->findOwned($teacher, $window->getId()));
        self::assertNull($repository->findOwned($otherTeacher, $window->getId()));
        self::assertNull($repository->findOwned($teacher, Uuid::v7()));
    }

    public function test_find_one_by_token_hash(): void
    {
        self::bootKernel();
        $window = CheckInWindowFactory::createOne(['tokenHash' => hash('sha256', 'known token')]);
        CheckInWindowFactory::createOne();
        $repository = self::repository();

        self::assertSame($window, $repository->findOneByTokenHash(hash('sha256', 'known token')));
        self::assertNull($repository->findOneByTokenHash(hash('sha256', 'unknown token')));
    }

    private static function repository(): CheckInWindowRepository
    {
        $repository = self::getContainer()->get(CheckInWindowRepository::class);
        self::assertInstanceOf(CheckInWindowRepository::class, $repository);

        return $repository;
    }
}
