<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\CheckIn\Repository\CheckInWindowRepository;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInWindowRepositoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

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

    public function test_find_active_for_lesson_returns_the_newest_active_window(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        $lesson = LessonSessionFactory::createOne();
        $older = new \DateTimeImmutable('2026-03-10 18:30:00', new \DateTimeZone('UTC'));
        CheckInWindowFactory::createOne(['lessonSession' => $lesson, 'openedAt' => $older, 'expiresAt' => $older->modify('+60 minutes')]);
        $newest = CheckInWindowFactory::createOne(['lessonSession' => $lesson]);
        CheckInWindowFactory::createOne();

        $found = self::repository()->findActiveForLesson($lesson, new \DateTimeImmutable('2026-03-10 19:10:00', new \DateTimeZone('UTC')));

        self::assertSame($newest, $found);
    }

    public function test_find_active_for_lesson_ignores_closed_and_expired_windows(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        $lesson = LessonSessionFactory::createOne();
        CheckInWindowFactory::new()->closed()->create(['lessonSession' => $lesson]);
        $expired = CheckInWindowFactory::createOne(['lessonSession' => $lesson]);
        $repository = self::repository();

        self::assertSame($expired, $repository->findActiveForLesson($lesson, $expired->getExpiresAt()->modify('-1 second')));
        self::assertNull($repository->findActiveForLesson($lesson, $expired->getExpiresAt()));
        self::assertNull($repository->findActiveForLesson(LessonSessionFactory::createOne(), new \DateTimeImmutable('2026-03-10 19:00:00', new \DateTimeZone('UTC'))));
    }

    private static function repository(): CheckInWindowRepository
    {
        $repository = self::getContainer()->get(CheckInWindowRepository::class);
        self::assertInstanceOf(CheckInWindowRepository::class, $repository);

        return $repository;
    }
}
