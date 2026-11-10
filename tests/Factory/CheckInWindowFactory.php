<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\CheckIn\Entity\CheckInWindow;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CheckInWindow>
 */
final class CheckInWindowFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CheckInWindow::class;
    }

    public function closed(): self
    {
        return $this->afterInstantiate(static function (CheckInWindow $window): void {
            $window->close(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $now = Clock::get()->now();

        return [
            'lessonSession' => LessonSessionFactory::new(),
            'tokenHash' => hash('sha256', random_bytes(32)),
            'openedAt' => $now,
            'expiresAt' => $now->modify('+60 minutes'),
        ];
    }
}
