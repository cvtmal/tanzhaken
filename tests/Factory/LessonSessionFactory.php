<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Courses\Entity\LessonSession;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<LessonSession>
 */
final class LessonSessionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return LessonSession::class;
    }

    public function completed(): self
    {
        return $this->afterInstantiate(static function (LessonSession $lesson): void {
            $lesson->complete();
        });
    }

    public function cancelled(): self
    {
        return $this->afterInstantiate(static function (LessonSession $lesson): void {
            $lesson->cancel(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // A unique minute offset keeps (course_id, starts_at_utc) unique for several lessons of one course.
        $startsAt = Clock::get()->now()->modify(\sprintf('+%d minutes', self::faker()->unique()->numberBetween(1, 1_000_000)));

        return [
            'course' => CourseFactory::new(),
            'startsAtUtc' => $startsAt,
            'endsAtUtc' => $startsAt->modify('+60 minutes'),
        ];
    }
}
