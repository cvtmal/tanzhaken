<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_token_hash_is_unique(): void
    {
        self::bootKernel();
        $window = CheckInWindowFactory::createOne();
        $otherLesson = LessonSessionFactory::createOne();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/check_in_window\.token_hash/');

        self::insertRow('check_in_window', self::windowRow($otherLesson->getId(), $window->getTokenHash()));
    }

    public function test_window_needs_an_existing_lesson(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('check_in_window', self::windowRow(Uuid::v7(), hash('sha256', 'unknown lesson')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function windowRow(Uuid $lessonId, string $tokenHash): array
    {
        $openedAt = new \DateTimeImmutable('2026-01-06 18:55:00');

        return [
            'id' => Uuid::v7(),
            'lesson_session_id' => $lessonId,
            'token_hash' => $tokenHash,
            'opened_at' => $openedAt,
            'expires_at' => $openedAt->modify('+60 minutes'),
        ];
    }
}
